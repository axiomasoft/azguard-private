<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use App\Domain\ValueObject\Email;

/**
 * DTO («голый», без spatie/laravel-data): структура для переноса данных между слоями.
 * - final readonly, promoted constructor properties — типизированный контракт;
 * - НЕТ поведения и НЕТ инвариантов сверх типов (это отличие от VO);
 * - НЕТ зависимостей от инфраструктуры (Request, Eloquent, DB);
 * - создаётся именованными аргументами (см. php/named-arguments);
 * - суффикс Data (данные) или Form / Command (вход команды) — см. php/naming-conventions.
 *
 * Когда нужны валидация-из-запроса, касты, #[TypeScript], DataCollection —
 * это spatie/laravel-data (см. php/laravel-data), а не этот «голый» DTO.
 */
final readonly class CreateUserData
{
    public function __construct(
        public Email $email,       // поле может быть VO — DTO переносит уже валидное значение
        public string $name,
        public ?string $locale = null,
    ) {}
}

/**
 * Delta-DTO: результат sync-операции (кого добавили / удалили).
 * Типовой возврат store-репозитория, чтобы Action знал, кого уведомлять,
 * не перечитывая базу (см. php/repositories).
 */
final readonly class Delta
{
    /**
     * @param  list<int>  $added
     * @param  list<int>  $removed
     */
    public function __construct(
        public array $added,
        public array $removed,
    ) {}
}
