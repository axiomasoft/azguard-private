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
 * ТОНКИЙ КОНТРОЛЛЕР: authorize → FormRequest → mapper → Action → redirect/Inertia.
 *
 * Два канала DI в контроллере:
 * 1. Constructor injection — зависимости, нужные нескольким экшен-методам (mapper, read-репозиторий).
 * 2. Method injection — Action, нужный ровно одному экшен-методу контроллера.
 *    Контейнер резолвит параметры роутового метода так же, как конструктор.
 *
 * Никакой бизнес-логики: валидация — в FormRequest, авторизация — в Policy
 * (атрибут #[CheckPermission] или $this->authorize), доменная работа — в Action.
 */
final class DocumentsController extends Controller
{
    public function __construct(
        private readonly DocumentFormRequestMapper $formRequestMapper,
    ) {}

    /**
     * Сохраняет документ и перенаправляет на карточку.
     * Action попадает сюда через METHOD INJECTION — он нужен только этому методу.
     */
    public function store(
        StoreMainRequest $request,
        StoreAction $action,
    ): JsonResponse|RedirectResponse {
        $isUpdate = $request->filled(key: 'id');

        // Request → DTO на границе HTTP: глубже Request не проходит.
        $document = $action->execute(
            command: new StoreCommand(
                form: $this->mapFormFromRequest(request: $request),
                user: $request->user(),
            ),
        );

        return to_route(route: 'documents.show', parameters: ['document' => $document->id])
            ->withFlash(
                message: $isUpdate ? 'Успешно сохранено' : 'Успешно создано',
                type: 'success',
            );
    }

    /**
     * Завершает документ с положительным или отрицательным результатом.
     * Авторизация — декларативно атрибутом, маппится на Policy.
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
     * Чтение без Action: страница собирается из DTO формы, рендер — Inertia.
     */
    #[CheckPermission(permission: CommonPermission::View, arguments: ['document'])]
    public function show(Document $document): Response
    {
        return Inertia::render(component: 'Documents/Show', props: [
            'documentForm' => Form::fromDb(document: $document)->toArray(),
        ]);
    }

    /**
     * Валидированный payload → DTO формы. Mapper — constructor-зависимость:
     * он нужен и store(), и register(), и другим методам записи.
     */
    private function mapFormFromRequest(StoreMainRequest $request): Form
    {
        return $this->formRequestMapper->mapToDto(
            validated: $request->validated(),
            actor: $request->user(),
        );
    }
}
