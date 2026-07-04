import { useEffect, useState } from 'react';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import * as A from '@/lib/access';
import type { PermGroup, Role, ShopUser } from '@/types';
import '@/styles/access.css';

function errMsg(e: unknown, fallback = 'Something went wrong.'): string {
  const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  if (data?.errors) {
    const first = Object.values(data.errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return data?.message || fallback;
}

const initial = (name: string) => (Array.from(name.trim())[0] || '?').toUpperCase();

type EditKey = number | 'new' | null;

export default function AccessControl() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [users, setUsers] = useState<ShopUser[]>([]);
  const [groups, setGroups] = useState<Record<string, PermGroup>>({});
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const [r, u, g] = await Promise.all([A.listRoles(), A.listUsers(), A.listPermissionGroups()]);
        setRoles(r);
        setUsers(u);
        setGroups(g);
      } catch (e) {
        setLoadError(errMsg(e, 'Could not load access settings.'));
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  return (
    <div className="m-screen c-access"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Access Control</h1>
        <p className="c-page-sub">Manage who can log in and what they can do.</p>
      </div>

      {loadError && <div className="ac-error">{loadError}</div>}

      {loading ? (
        <div className="ac-empty">Loading…</div>
      ) : (
        <div className="ac-grid">
          <section className="ac-block"><UsersSection users={users} roles={roles} onChange={setUsers} /></section>
          <section className="ac-block"><RolesSection roles={roles} groups={groups} onChange={setRoles} /></section>
        </div>
      )}
    </div></div>
  );
}

/* -------------------------------------------------------------------------- */
/* Users                                                                      */
/* -------------------------------------------------------------------------- */

function UsersSection({
  users,
  roles,
  onChange,
}: {
  users: ShopUser[];
  roles: Role[];
  onChange: (u: ShopUser[]) => void;
}) {
  const { currentUser } = useShop();
  const [edit, setEdit] = useState<EditKey>(null);

  const upsert = (u: ShopUser) => {
    const exists = users.some((x) => x.id === u.id);
    onChange(exists ? users.map((x) => (x.id === u.id ? u : x)) : [...users, u]);
    setEdit(null);
  };

  const remove = async (u: ShopUser) => {
    if (!window.confirm(`Delete user "${u.name}"?`)) return;
    try {
      await A.deleteUser(u.id);
      onChange(users.filter((x) => x.id !== u.id));
    } catch (e) {
      window.alert(errMsg(e, 'Could not delete user.'));
    }
  };

  return (
    <>
      <div className="ac-sec-head">
        <span className="ac-sec-title">Users</span>
        {edit !== 'new' && (
          <button className="ac-add-btn" onClick={() => setEdit('new')}>
            <Icons.Plus size={15} /> Add
          </button>
        )}
      </div>

      {edit === 'new' && (
        <UserEditor user={null} roles={roles} onCancel={() => setEdit(null)} onSaved={upsert} />
      )}

      {users.length === 0 && edit !== 'new' && (
        <div className="ac-empty">No users yet. Add your first team member.</div>
      )}

      {users.map((u) =>
        edit === u.id ? (
          <UserEditor key={u.id} user={u} roles={roles} onCancel={() => setEdit(null)} onSaved={upsert} />
        ) : (
          <div className="ac-card" key={u.id}>
            <div className="ac-row">
              <span className="ac-avatar">{initial(u.name)}</span>
              <span className="ac-row-body">
                <span className="ac-row-name">
                  {u.name}
                  {currentUser?.id === u.id && <span className="ac-tag ac-tag-muted">You</span>}
                </span>
                <span className="ac-row-sub">
                  {u.role ? <span className="ac-tag">{u.role.name}</span> : <span className="ac-tag ac-tag-muted">No role</span>}
                  {!u.is_active && <span className="ac-tag ac-tag-off">Inactive</span>}
                </span>
              </span>
              <span className="ac-row-actions">
                <button className="c-icon-btn" aria-label="Edit" onClick={() => setEdit(u.id)}>
                  <Icons.Edit size={16} />
                </button>
                <button className="c-icon-btn ac-danger" aria-label="Delete" onClick={() => void remove(u)}>
                  <Icons.Trash size={16} />
                </button>
              </span>
            </div>
          </div>
        ),
      )}
    </>
  );
}

function UserEditor({
  user,
  roles,
  onCancel,
  onSaved,
}: {
  user: ShopUser | null;
  roles: Role[];
  onCancel: () => void;
  onSaved: (u: ShopUser) => void;
}) {
  const isEdit = !!user;
  const [name, setName] = useState(user?.name ?? '');
  const [pin, setPin] = useState('');
  const [roleId, setRoleId] = useState<number | null>(user?.role?.id ?? null);
  const [active, setActive] = useState(user?.is_active ?? true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const save = async () => {
    if (saving) return;
    if (!name.trim()) { setError('Name is required.'); return; }
    if (!isEdit && !pin.trim()) { setError('PIN is required.'); return; }
    setSaving(true);
    setError('');
    try {
      const saved = isEdit
        ? await A.updateUser(user!.id, { name: name.trim(), login_pin: pin.trim() || undefined, role_id: roleId, is_active: active })
        : await A.createUser({ name: name.trim(), login_pin: pin.trim(), role_id: roleId, is_active: active });
      onSaved(saved);
    } catch (e) {
      setError(errMsg(e, 'Could not save user.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="ac-card ac-editor">
      <div className="ac-editor-title">{isEdit ? 'Edit user' : 'New user'}</div>
      {error && <div className="ac-error" style={{ margin: '0 0 12px' }}>{error}</div>}

      <div className="ac-field">
        <label className="ac-label">Name</label>
        <input className="ac-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Sara" />
      </div>

      <div className="ac-field">
        <label className="ac-label">{isEdit ? 'PIN — leave blank to keep' : 'Login PIN'}</label>
        <input
          className="ac-input"
          inputMode="numeric"
          value={pin}
          onChange={(e) => setPin(e.target.value)}
          placeholder={isEdit ? '••••' : 'Logs in with Business ID + this PIN'}
        />
      </div>

      <div className="ac-field">
        <label className="ac-label">Role</label>
        <select className="ac-select" value={roleId ?? ''} onChange={(e) => setRoleId(e.target.value ? Number(e.target.value) : null)}>
          <option value="">No role</option>
          {roles.map((r) => (
            <option key={r.id} value={r.id}>{r.name}</option>
          ))}
        </select>
      </div>

      <label className="ac-check">
        <span
          className={`c-toggle ${active ? 'on' : ''}`}
          role="switch"
          aria-checked={active}
          onClick={() => setActive((v) => !v)}
        >
          <span className="c-toggle-knob" />
        </span>
        Active — can log in
      </label>

      <div className="ac-editor-actions">
        <button className="c-btn c-btn-ghost" onClick={onCancel}>Cancel</button>
        <button className="c-btn" disabled={saving} onClick={() => void save()}>
          {saving ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Roles                                                                      */
/* -------------------------------------------------------------------------- */

function RolesSection({
  roles,
  groups,
  onChange,
}: {
  roles: Role[];
  groups: Record<string, PermGroup>;
  onChange: (r: Role[]) => void;
}) {
  const [edit, setEdit] = useState<EditKey>(null);

  const upsert = (r: Role) => {
    const exists = roles.some((x) => x.id === r.id);
    onChange(exists ? roles.map((x) => (x.id === r.id ? r : x)) : [...roles, r]);
    setEdit(null);
  };

  const remove = async (r: Role) => {
    if (!window.confirm(`Delete role "${r.name}"?`)) return;
    try {
      await A.deleteRole(r.id);
      onChange(roles.filter((x) => x.id !== r.id));
    } catch (e) {
      window.alert(errMsg(e, 'Could not delete role.'));
    }
  };

  return (
    <>
      <div className="ac-sec-head">
        <span className="ac-sec-title">Roles</span>
        {edit !== 'new' && (
          <button className="ac-add-btn" onClick={() => setEdit('new')}>
            <Icons.Plus size={15} /> Add
          </button>
        )}
      </div>

      {edit === 'new' && (
        <RoleEditor role={null} groups={groups} onCancel={() => setEdit(null)} onSaved={upsert} />
      )}

      {roles.map((r) =>
        edit === r.id ? (
          <RoleEditor key={r.id} role={r} groups={groups} onCancel={() => setEdit(null)} onSaved={upsert} />
        ) : (
          <div className="ac-card" key={r.id}>
            <div className="ac-row">
              <span className="ac-avatar"><Icons.Key size={18} /></span>
              <span className="ac-row-body">
                <span className="ac-row-name">
                  {r.name}
                  {r.is_owner && <span className="ac-tag ac-tag-muted">Locked</span>}
                </span>
                <span className="ac-row-sub">
                  {r.is_owner ? 'Full access to everything' : `${r.permissions.length} permission${r.permissions.length === 1 ? '' : 's'}`}
                </span>
              </span>
              <span className="ac-row-actions">
                <button className="c-icon-btn" aria-label="Edit" disabled={r.is_owner} onClick={() => setEdit(r.id)}>
                  <Icons.Edit size={16} />
                </button>
                <button className="c-icon-btn ac-danger" aria-label="Delete" disabled={r.is_owner} onClick={() => void remove(r)}>
                  <Icons.Trash size={16} />
                </button>
              </span>
            </div>
          </div>
        ),
      )}
    </>
  );
}

function RoleEditor({
  role,
  groups,
  onCancel,
  onSaved,
}: {
  role: Role | null;
  groups: Record<string, PermGroup>;
  onCancel: () => void;
  onSaved: (r: Role) => void;
}) {
  const isEdit = !!role;
  const [name, setName] = useState(role?.name ?? '');
  const [selected, setSelected] = useState<Set<string>>(new Set(role?.permissions ?? []));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const toggle = (perm: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(perm)) next.delete(perm); else next.add(perm);
      return next;
    });
  };

  const toggleGroup = (perms: string[], allOn: boolean) => {
    setSelected((prev) => {
      const next = new Set(prev);
      perms.forEach((p) => (allOn ? next.delete(p) : next.add(p)));
      return next;
    });
  };

  const save = async () => {
    if (saving) return;
    if (!name.trim()) { setError('Role name is required.'); return; }
    setSaving(true);
    setError('');
    const payload = { name: name.trim(), permissions: Array.from(selected) };
    try {
      const saved = isEdit ? await A.updateRole(role!.id, payload) : await A.createRole(payload);
      onSaved(saved);
    } catch (e) {
      setError(errMsg(e, 'Could not save role.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="ac-card ac-editor">
      <div className="ac-editor-title">{isEdit ? 'Edit role' : 'New role'}</div>
      {error && <div className="ac-error" style={{ margin: '0 0 12px' }}>{error}</div>}

      <div className="ac-field">
        <label className="ac-label">Role name</label>
        <input className="ac-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Receptionist" />
      </div>

      <label className="ac-label" style={{ marginBottom: 4 }}>Permissions</label>
      {Object.entries(groups).map(([key, group]) => {
        const perms = Object.keys(group.permissions);
        const allOn = perms.every((p) => selected.has(p));
        return (
          <div className="ac-matrix-group" key={key}>
            <div className="ac-matrix-head">
              <span className="ac-matrix-label">{group.label}</span>
              <button className="ac-selectall" onClick={() => toggleGroup(perms, allOn)}>
                {allOn ? 'Clear' : 'Select all'}
              </button>
            </div>
            {Object.entries(group.permissions).map(([perm, label]) => (
              <label className="ac-perm" key={perm}>
                <input type="checkbox" checked={selected.has(perm)} onChange={() => toggle(perm)} />
                {label}
              </label>
            ))}
          </div>
        );
      })}

      <div className="ac-editor-actions">
        <button className="c-btn c-btn-ghost" onClick={onCancel}>Cancel</button>
        <button className="c-btn" disabled={saving} onClick={() => void save()}>
          {saving ? 'Saving…' : 'Save role'}
        </button>
      </div>
    </div>
  );
}

