<?php

declare(strict_types=1);

namespace App\Domain\Pipeline;

/**
 * Pipeline: последовательная трансформация одного значения цепочкой шагов.
 * Каждый шаг — самостоятельный, тестируемый, переставляемый объект/замыкание.
 * Подходит, когда обработка распадается на этапы (нормализация → обогащение → расчёт).
 *
 * В Laravel есть встроенный Illuminate\Pipeline\Pipeline и фасад Pipeline —
 * используй его; этот класс показывает контракт, когда фреймворковый недоступен
 * или зависимость нежелательна.
 */

interface PipeStage
{
    public function handle(mixed $payload, callable $next): mixed;
}

final class Pipeline
{
    /** @var list<PipeStage> */
    private array $stages = [];

    /** @param list<PipeStage> $stages */
    public function through(array $stages): self
    {
        $this->stages = $stages;

        return $this;
    }

    public function process(mixed $payload): mixed
    {
        // handle() вызывается позиционно: имена параметров реализации шага не
        // фиксированы интерфейсом PipeStage — named arg через интерфейс ломает полиморфизм.
        $chain = array_reduce(
            array: array_reverse($this->stages),
            callback: fn (callable $next, PipeStage $stage): callable
                => fn (mixed $value): mixed => $stage->handle($value, $next),
            initial: fn (mixed $value): mixed => $value,
        );

        return $chain($payload);
    }
}

/**
 * Пример шага: чистый, без побочных эффектов на чужие данные, можно тестировать в изоляции.
 * Вызов в Action: (new Pipeline())->through([new TrimName(), new Capitalize()])->process($data);
 */
final class TrimName implements PipeStage
{
    public function handle(mixed $payload, callable $next): mixed
    {
        $payload->name = trim(string: $payload->name);

        return $next($payload);
    }
}
