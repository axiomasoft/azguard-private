# F31 — cleanup-before-finally

**Статус:** точечный Perplexity synthesis; первичные факты сверяются отдельно, ответ не является доказательством поведения репозитория.

## Данный контекст и пробел

Earlier R06 covers scoped lifecycle generally, but did not inspect this exact failure. AuthorizationContextManager is scoped. ContextGuard::checkInContext() executes manager->set(temp); resolver->forgetRequestCache(user,panel); try { resolver->forUser(...) } finally { restore(previous); resolver->forgetRequestCache(...); }. A custom PermissionResolverInterface implementation may throw in the first forgetRequestCache; a no-DB probe shows the temporary context remains active in that same request.

## Вопрос

Choose an exact safe scope API for nested context checks where BOTH entry invalidation and exit invalidation may throw. Compare try/finally placement, restoring previous state before secondary invalidation, explicit context request without mutation, and whether to suppress/aggregate cleanup error. Provide one minimal PHP pseudocode sketch and failure-case table. Cite only official Laravel scoped-lifetime docs and PHP Throwable/finally semantics; state what is design inference.

## Ответ

Точное решение: заменить checkInContext() на API вида runInContext($temporary, $callback), который сохраняет предыдущее состояние, устанавливает временный контекст, а затем гарантированно восстанавливает предыдущий контекст до попытки exit-инвалидации. Обе инвалидации должны находиться внутри защищённого try; ошибку очистки нельзя позволять затереть ошибку callback без явной политики.
```
function runInContext(Context $temporary, callable $callback): mixed { $previous = $manager->current(); try { $manager->set($temporary); $resolver->forgetRequestCache($user, $panel); // entry invalidation return $callback( fn () => $resolver->forUser($user, $panel) ); } finally { $manager->set($previous); // restore before fallible cleanup $resolver->forgetRequestCache($user, $panel); // exit invalidation } }
```
Этот скетч обеспечивает восстановление при сбое entry-инвалидации, потому что она уже внутри try. Но если exit-инвалидация выбросит исключение, оно может заменить исключение callback: PHP гарантирует выполнение finally, а исключение из finally становится распространяемым, сохраняя исходное как previous.

## Сравнение вариантов

Laravel scoped означает один экземпляр на request/job lifecycle с очисткой при начале нового lifecycle; это не гарантирует откат мутаций объекта внутри текущего lifecycle.

## Ошибки очистки

Не подавлять Throwable молча: это скрывает неудачную инвалидацию. Если важно сохранить первичную ошибку callback, перехватите её, выполните restore, попытайтесь exit-инвалидацию, а при двух ошибках выбросьте агрегирующее исключение с обеими причинами; при одной ошибке — выбросьте её. Throwable покрывает и Exception, и Error.
Проектный вывод: для текущего изменяемого manager выбрать восстановление до exit-инвалидации и явную политику агрегации. Если допустимо менять интерфейсы, передача context аргументом лучше устраняет сам класс утечки. Предпосылка: set(previous) не бросает; если он тоже может бросить, нужен отдельный контракт восстановления/аварийная стратегия. Scoped lifetime Laravel эту локальную гарантию не заменяет.

Sources:
1. [PHP exceptions manual](https://www.php.net/manual/en/language.exceptions.php)
2. [Laravel 13 service container](https://laravel.com/framework/docs/container)
