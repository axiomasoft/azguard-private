# P4.4 candidate invariants

1. Raw assignments are restricted by panel, exact subject type/key and an OR of complete tenant/context pairs. HostKeyColumns canonicalization and final identity getters validate duplicated persisted identity. Malformed contributions invalidate the whole read; role expansion remains core responsibility.
2. All schema/state/assignment reads within a DatabaseSource attempt use one pinned PDO. Primary uses write authority outside unknown transactions. Default uses the configured raw read PDO without sticky/write fallback; an unsplit connection has one configured PDO. Replica delay is permitted and has no package freshness bound. Unknown transactions fail only when authority is consumed; static PolicyOnly reads no assignments/state/dynamic data.
3. T_before -> all contribution capabilities -> T_after. Changed token discards the complete set; at most three attempts, then ConsistencyError. Generic FencesReads follows the same whole-set rule. Sources with no contribution capability are not fenced on scalar Grants paths. An absent panel_state produces a deterministic nonwriting initial token.
4. Bound model hydration preserves raw role key, pattern, origin and UTC expiry. Only declared decisionFields are exposed through GrantFields. rolesOnly never queries permission_grants. Actual source object, rather than a contribution display label, controls the FolderSource automatic-role exception.
5. Selection is a prefilter with raw witnesses, never Allow. Exact tenant/context-type filtering, ref deduplication and whole-set fencing preserve every witness including multiple rows per scope. Factories validate list shapes; 500-row chunks and a combined 10000-witness budget bound enumeration.
6. Storage::own is keyed by resolved connection/prefix using IdentityCodec::compose and a 60-hex SHA256 suffix. Same physical pair reuses identity and requires matching host keys; different prefixes and explicit id collisions remain distinct/error.
7. StoresGrants::transaction delegates to Storage::mutate for its bound panel. Apply/dynamic writes are outside P4.4. Dynamic opt-in is represented but overlay execution gives an explicit DefinitionException until P4.17.

Affected paths: direct grants/roleGrants, combined readContributions, contextsCovering,
state, scalar AuthorityStage and configured/owned storage. SQLite plus PostgreSQL,
MySQL and MariaDB are covered by candidate-bound checks; this is not a claim of
scoped Authorizer/P08 or protected host-write integration.
