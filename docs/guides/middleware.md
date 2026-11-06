# Middleware denial responses

`azguard.can` denies with a generic 403 message by default. Laravel's normal HTML and JSON renderers do not
include the internal decision explanation. The `AuthorizationException` carries a stable reason code in
`response()->code()` when a decision was made; it is internal metadata, not an automatically published explanation.
A guest or an unaccepted subject can have no code. Custom exception renderers and `#[CheckPermission]` messages
are controlled by the host and should avoid exposing sensitive context.

For opt-in 503 and 500 mapping of failed decisions, see [Checking access](checking-access.md#503-and-500-for-failed-decisions-opt-in).
