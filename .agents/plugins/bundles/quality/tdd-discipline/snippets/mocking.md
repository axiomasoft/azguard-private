# Choose the boundary under test

Use real collaborators across the integration boundary being verified. Fake unrelated external
services, time and randomness for deterministic local tests. A focused unit test may replace
internal collaborators when that isolates a stable component contract; it does not establish
that their real integration works.

Inject collaborators at a useful seam. Prefer a small operation-specific fake over reproducing
a whole dependency's internals. Keep DB/queue integration checks on an isolated environment
with relevant production semantics. External payments and sends use sandboxes/fakes.

Assert call counts/order when they are the behavior at risk, such as avoiding a duplicate
charge after retry. Otherwise favor observable outcomes over mirroring implementation calls.
