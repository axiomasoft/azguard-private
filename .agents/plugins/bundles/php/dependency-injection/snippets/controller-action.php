<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Actions\Document\Common\FinishAction;
use App\Actions\Document\Common\StoreAction;
use App\Attributes\CheckPermission;
use App\Dto\Actions\Document\Common\FinishCommand;
use App\Dto\Actions\Document\Common\StoreCommand;
use App\Dto\Document\Form\Form;
use App\Dto\Document\Form\DocumentFormRequestMapper;
use App\Enums\Document\Permissions\CommonPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreMainRequest;
use App\Models\Document\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

use function to_route;

/**
 * THIN CONTROLLER: authorize → FormRequest → mapper → Action → redirect/Inertia.
 *
 * Two channels DI in the controller:
 * 1. Constructor injection — dependencies required by several action methods (mapper, read-repository).
 * 2. Method injection — Action, needed by exactly one controller action method.
 *    The container resolves route method parameters in the same way as a constructor.
 *
 * No business logic: validation - in FormRequest, authorization - in Policy
 * (attribute #[CheckPermission] or $this->authorize), domain work - in Action.
 */
final class DocumentsController extends Controller
{
    public function __construct(
        private readonly DocumentFormRequestMapper $formRequestMapper,
    ) {}

    /**
     * Saves the document and redirects to the card.
     * Action gets here via METHOD INJECTION — only this method needs it.
     */
    public function store(
        StoreMainRequest $request,
        StoreAction $action,
    ): JsonResponse|RedirectResponse {
        $isUpdate = $request->filled(key: 'id');

        // Request → DTO at the border HTTP: deeper Request does not work.
        $document = $action->execute(
            command: new StoreCommand(
                form: $this->mapFormFromRequest(request: $request),
                user: $request->user(),
            ),
        );

        return to_route(route: 'documents.show', parameters: ['document' => $document->id])
            ->withFlash(
                message: $isUpdate ? 'Successfully saved' : 'Successfully created',
                type: 'success',
            );
    }

    /**
     * Ends the document with a positive or negative result.
     * Authorization - declarative attribute, mapped to Policy.
     */
    #[CheckPermission(permission: CommonPermission::Finish, arguments: ['document'])]
    public function finish(
        Document $document,
        Request $request,
        FinishAction $action,
    ): JsonResponse|RedirectResponse|Response {
        $action->execute(
            command: new FinishCommand(
                document: $document,
                user: $request->user(),
                result: (int) $request->input(key: 'result') === 1,
                comment: $request->input(key: 'comment'),
            ),
        );

        return to_route(route: 'documents.show', parameters: ['document' => $document->id]);
    }

    /**
     * Read without Action: page is assembled from DTO forms, render - Inertia.
     */
    #[CheckPermission(permission: CommonPermission::View, arguments: ['document'])]
    public function show(Document $document): Response
    {
        return Inertia::render(component: 'Documents/Show', props: [
            'documentForm' => Form::fromDb(document: $document)->toArray(),
        ]);
    }

    /**
     * Validated payload → DTO forms. Mapper — constructor-dependency:
     * it is needed and store(), and register(), and other recording methods.
     */
    private function mapFormFromRequest(StoreMainRequest $request): Form
    {
        return $this->formRequestMapper->mapToDto(
            validated: $request->validated(),
            actor: $request->user(),
        );
    }
}
