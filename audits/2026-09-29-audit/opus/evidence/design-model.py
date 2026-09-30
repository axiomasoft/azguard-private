# Historical model of the pre-D83 policy-OR-grants hypothesis; NOT proof of the current authority modes.
"""Bounded CRM specification model, not AzGuard PHP implementation qualification.

Run: python3 audits/2026-09-29-audit/opus/evidence/design-model.py
SQLite tables materialize static/dynamic role definitions per tenant for the model.
They are not a migration generator or the complete normative schema in 08.
"""
import itertools
import sqlite3
import unittest


def database():
    db = sqlite3.connect(':memory:')
    db.execute('PRAGMA foreign_keys=ON')
    db.executescript('''
    CREATE TABLE projects(tenant TEXT, id TEXT, PRIMARY KEY(tenant,id));
    CREATE TABLE clients(tenant TEXT, id TEXT, project TEXT, blocked INT,
      PRIMARY KEY(tenant,id), FOREIGN KEY(tenant,project) REFERENCES projects(tenant,id));
    CREATE TABLE memberships(subject TEXT, tenant TEXT, PRIMARY KEY(subject,tenant));
    CREATE TABLE roles(panel TEXT, tenant TEXT, role TEXT, permission TEXT, super INT,
      PRIMARY KEY(panel,tenant,role));
    CREATE TABLE grants(panel TEXT, tenant TEXT, subject TEXT, role TEXT, context TEXT,
      origin TEXT, expires INT, weekday INT, department TEXT,
      UNIQUE(panel,tenant,subject,role,context,origin));
    CREATE TABLE state(panel TEXT PRIMARY KEY, version INT, incarnation TEXT);
    INSERT INTO state VALUES('crm',0,'initial');
    INSERT INTO state VALUES('backoffice',0,'initial');
    ''')
    for tenant in ('A', 'B'):
        for project in ('1', '2'):
            db.execute('INSERT INTO projects VALUES(?,?)', (tenant, project))
            for blocked in (0, 1):
                db.execute('INSERT INTO clients VALUES(?,?,?,?)',
                           (tenant, project + str(blocked), project, blocked))
        db.execute('INSERT INTO memberships VALUES(?,?)', ('anna', tenant))
        for panel in ('crm', 'backoffice'):
            for role, perm, super_admin in (('caller', 'update', 0), ('analyst', 'view', 0), ('owner', '', 1)):
                db.execute('INSERT INTO roles VALUES(?,?,?,?,?)', (panel, tenant, role, perm, super_admin))
    db.commit()
    return db


def scalar(db, panel, tenant, client, permission, token=True, locked=False, now=10, day=1, department='sales'):
    if locked or not token or client[0] != tenant:
        return False
    if not db.execute('SELECT 1 FROM memberships WHERE subject=? AND tenant=?', ('anna', tenant)).fetchone():
        return False
    granted = False
    super_admin = False
    for g in db.execute('SELECT * FROM grants'):
        gp, gt, subject, role, context, origin, expires, weekday, dept = g
        if gp != panel or gt != tenant or subject != 'anna' or context not in ('global', client[2]):
            continue
        if expires is not None and expires <= now:
            continue
        if weekday is not None and weekday != day:
            continue
        if dept is not None and dept != department:
            continue
        definition = db.execute('SELECT permission,super FROM roles WHERE panel=? AND tenant=? AND role=?',
                                (panel, tenant, role)).fetchone()
        if definition is None:
            continue
        granted |= definition[0] == permission or (definition[0] == 'update' and permission == 'view')
        super_admin |= bool(definition[1])
    # Superadmin bypasses the example policy, but not tenant/membership/token/account boundaries.
    return super_admin or (granted and (permission != 'update' or not client[3]))


def visible(db, panel, tenant, permission, token=True, locked=False, now=10, day=1, department='sales'):
    rows = db.execute('''
    SELECT c.id FROM clients c
    WHERE c.tenant=:tenant AND :token=1 AND :locked=0
      AND EXISTS(SELECT 1 FROM memberships m WHERE m.tenant=c.tenant AND m.subject='anna')
      AND EXISTS(
        SELECT 1 FROM grants g JOIN roles r
          ON r.panel=g.panel AND r.tenant=g.tenant AND r.role=g.role
        WHERE g.panel=:panel AND g.tenant=c.tenant AND g.subject='anna'
          AND (g.context='global' OR g.context=c.project)
          AND (g.expires IS NULL OR g.expires>:now)
          AND (g.weekday IS NULL OR g.weekday=:day)
          AND (g.department IS NULL OR g.department=:department)
          AND (r.super=1 OR ((r.permission=:permission OR (r.permission='update' AND :permission='view'))
            AND (:permission!='update' OR c.blocked=0)))
      ) ORDER BY c.id
    ''', dict(panel=panel, tenant=tenant, permission=permission, token=int(token), locked=int(locked),
              now=now, day=day, department=department))
    return {row[0] for row in rows}


def insert(db, panel='crm', tenant='A', role='caller', context='1', origin='manual', expires=None,
           weekday=None, department=None):
    db.execute('INSERT INTO grants VALUES(?,?,?,?,?,?,?,?,?)',
               (panel, tenant, 'anna', role, context, origin, expires, weekday, department))


class DesignModel(unittest.TestCase):
    def setUp(self):
        self.db = database()
        self.addCleanup(self.db.close)

    def test_scalar_and_exact_sql_in_bounded_cross_product(self):
        cases = [dict(tenant='A', role='caller', context='1'),
                 dict(tenant='A', role='analyst', context='2'),
                 dict(tenant='B', role='caller', context='1'),
                 dict(tenant='A', role='owner', context='global', expires=10),
                 dict(tenant='B', role='owner', context='global'),
                 dict(panel='backoffice', tenant='A', role='owner', context='global')]
        checks = 0
        for mask in range(1 << len(cases)):
            self.db.execute('DELETE FROM grants')
            for i, case in enumerate(cases):
                if mask & (1 << i):
                    insert(self.db, **case)
            for panel, tenant, permission, token, locked, now in itertools.product(
                    ('crm', 'backoffice'), ('A', 'B'), ('view', 'update'), (False, True), (False, True), (9, 10)):
                clients = self.db.execute('SELECT * FROM clients WHERE tenant=?', (tenant,)).fetchall()
                expected = {c[1] for c in clients if scalar(self.db, panel, tenant, c, permission, token, locked, now)}
                self.assertEqual(expected, visible(self.db, panel, tenant, permission, token, locked, now))
                checks += 1
        self.assertEqual(checks, 4096)
        print('Model: 4096 scalar/SQL set comparisons, 16384 record evaluations')

    def test_conditions_have_one_witness(self):
        insert(self.db, origin='manual', weekday=1, department='other')
        insert(self.db, origin='sync.crm-x', weekday=2, department='sales')
        self.assertFalse(visible(self.db, 'crm', 'A', 'view', day=1, department='sales'))
        insert(self.db, origin='sync.crm-y', weekday=1, department='sales')
        self.assertEqual({'10', '11'}, visible(self.db, 'crm', 'A', 'view'))

    def test_cross_tenant_explicit_scope_and_token_boundary(self):
        insert(self.db, tenant='A', role='owner', context='global')
        client_b = self.db.execute("SELECT * FROM clients WHERE tenant='B' LIMIT 1").fetchone()
        self.assertFalse(scalar(self.db, 'crm', 'A', client_b, 'view'))
        self.assertFalse(visible(self.db, 'crm', 'A', 'view', token=False))
        self.assertFalse(visible(self.db, 'crm', 'A', 'view', locked=True))

    def test_unique_scope_origin_and_revoke_partition(self):
        insert(self.db)
        with self.assertRaises(sqlite3.IntegrityError):
            insert(self.db)
        insert(self.db, tenant='B')
        insert(self.db, origin='sync.crm-x')
        self.db.execute("DELETE FROM grants WHERE panel='crm' AND tenant='A' AND origin='manual'")
        self.assertEqual(2, self.db.execute('SELECT count(*) FROM grants').fetchone()[0])
        self.assertTrue(visible(self.db, 'crm', 'A', 'view'))
        self.assertTrue(visible(self.db, 'crm', 'B', 'view'))

    def test_client_project_composite_foreign_key(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO clients VALUES('C','x','1',0)")

    def test_expiry_at_boundary_and_membership_revocation(self):
        insert(self.db, expires=10)
        self.assertTrue(visible(self.db, 'crm', 'A', 'view', now=9))
        self.assertFalse(visible(self.db, 'crm', 'A', 'view', now=10))
        self.db.execute("DELETE FROM memberships WHERE tenant='A'")
        self.assertFalse(visible(self.db, 'crm', 'A', 'view', now=9))

    def test_transaction_rolls_back_grant_and_version_together(self):
        self.db.execute('BEGIN')
        insert(self.db)
        self.db.execute("UPDATE state SET version=version+1 WHERE panel='crm'")
        self.db.rollback()
        self.assertEqual(0, self.db.execute('SELECT count(*) FROM grants').fetchone()[0])
        self.assertEqual(0, self.db.execute("SELECT version FROM state WHERE panel='crm'").fetchone()[0])
        with self.db:
            insert(self.db)
            self.db.execute("UPDATE state SET version=version+1 WHERE panel='crm'")
        self.assertEqual(1, self.db.execute("SELECT version FROM state WHERE panel='crm'").fetchone()[0])
        self.assertEqual(0, self.db.execute("SELECT version FROM state WHERE panel='backoffice'").fetchone()[0])

    def test_version_fence_and_incarnation_formula(self):
        # Algebra only: no claim of a two-process PostgreSQL race reproduction.
        accepted = lambda before, after: before == after
        self.assertTrue(accepted(('store', 'crm', 'old', 7), ('store', 'crm', 'old', 7)))
        self.assertFalse(accepted(('store', 'crm', 'old', 7), ('store', 'crm', 'old', 8)))
        self.assertFalse(accepted(('store', 'crm', 'old', 7), ('store', 'crm', 'new', 7)))


if __name__ == '__main__':
    unittest.main(verbosity=2)
