<?php

namespace Lupennat\NestedMany\Http\Requests;

use Exception;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;
use Lupennat\NestedMany\Models\Nested;
use Lupennat\NestedMany\NestedChildrenHelper;
class NestedActionRequest extends ActionRequest implements NestedResourceRequest
{
    use ChildrenResources {
        ChildrenResources::getProcessedNestedChildren as traitGetProcessedNestedChildren;
        ChildrenResources::getModel as traitGetModel;
    }

    protected $nestedRelationsShipModels = [];

    protected function getProcessedNestedChildren($request, $resourceClass)
    {
        return NestedChildrenHelper::getNestedChildrenModelAttributes($request, 'nestedChildren', $resourceClass, true);
    }

    /**
     * generate new Nested.
     */
    public function newNested(string $relationName = ''): Nested
    {
        if ($relationName) {
            if (!array_key_exists($relationName, $this->nestedRelationsShipModels)) {
                throw new Exception("relation {$relationName} is not managed by NestedMany!");
            }
            return new Nested($this->nestedRelationsShipModels[$relationName], [], true);
        }
        /** @var \Lupennat\NestedMany\Models\Contracts\Nestable $model */
        $model = $this->model();
        return new Nested($model, [], true);
    }

    protected function getModel($request, $resourceClass, $child)
    {
        $nested = $this->traitGetModel($request, $resourceClass, $child);

        $relations = [];

        foreach ($child['relations'] as $name => $relation) {

            $oldResourceName = $request->route('resource');
            $request->route()->setParameter('resource', $relation['resourceName']);
            $newRequest = NovaRequest::createFrom($request);
            $newRequest = NovaRequest::createFrom($newRequest->replace([
                'viaResourceId' => $nested->getKey() ?? null,
                'viaResource' => $resourceClass::uriKey(),
                'viaRelationship' => $relation['relationShip']
            ]));

            $this->nestedRelationsShipModels[$name] = $newRequest->model();
            $relations[$name] = $this->generateNestedChildren($newRequest, $relation['children'], $relation['resourceClass']);
            $request->route()->setParameter('resource', $oldResourceName);
        }

        $nested->nestedSetRelations($relations);

        return $nested;
    }

    /**
     * Get the nested action instance specified by the request.
     *
     * @return \Lupennat\NestedMany\Actions\NestedBaseAction
     */
    public function nestedAction()
    {
        return once(function () {
            $hasResources = !empty($this->nestedResources);

            return $this->availableActions()
                ->filter(function ($action) use ($hasResources) {
                    return $hasResources ? true : $action->isStandalone();
                })->first(function ($action) {
                    return $action->uriKey() == $this->query('action');
                }) ?: abort($this->actionExists() ? 403 : 404);
        });
    }

    /**
     * Override Nova's ActionRequest::validateFields() to avoid calling $this->action(),
     * which is strictly typed to return Laravel\Nova\Actions\Action.
     */
    public function validateFields(): void
    {
        $this->nestedAction()->validateFields($this);
    }

    /**
     * Override Nova's ActionRequest::resolveFields() to avoid calling $this->action().
     */
    public function resolveFields(): ActionFields
    {
        return once(function () {
            $fields = new Fluent;

            $results = (new FieldCollection($this->nestedAction()->fields($this)))
                ->authorized($this)
                ->applyDependsOn($this)
                ->withoutReadonly($this)
                ->withoutUnfillable()
                ->mapWithKeys(fn ($field) => [
                    $field->attribute => $field->fillForAction($this, $fields),
                ]);

            return new ActionFields(
                collect($fields->getAttributes()),
                $results->filter(static fn ($field) => \is_callable($field))
            );
        });
    }

    /**
     * Get the all actions for the request.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function resolveActions(): \Illuminate\Support\Collection
    {
        return $this->newResource()->resolveNestedActions($this)
            ->merge([
                $this->newResource()->resolveNestedAddAction($this),
                $this->newResource()->resolveNestedDeleteAction($this),
                $this->newResource()->resolveNestedRestoreAction($this),
            ]);
    }
}
