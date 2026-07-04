import { useEffect, useMemo, useState } from 'react';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import * as A from '@/lib/access';
import type { PermGroup, Role, ShopUser } from '@/types';
import '@/styles/access.css';

type Tab = 'users' | 'roles' | 'permissions';

function errMsg(e: unknown, fallback = 'Something went wrong.'): string {
  const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  if (data?.errors) {
    const first = Object.values(data.errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return data?.message || fallback;
}

export default function AccessControl() {
  const [tab, setTab] = useState<Tab>('users');
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

  const tabs: Tab[] = ['users', 'roles', 'permissions'];

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Access Control</h1>
        <p className="c-page-sub">Manage users, roles &amp; permissions for your business.</p>
      </div>

      <div className="ac-seg">
        {tabs.map((t) => (
          <button key={t} className={`ac-seg-btn ${tab === t ? 'on' : ''}`} onClick={() => setTab(t)}>
            {t === 'users' ? 'Users' : t === 'roles' ? 'Roles' : 'Permissions'}
          </button>
        ))}
      </div>

      {loadError && <div className="ac-empty">{loadError}</div>}

      {loading ? (
        <div className="ac-empty">Loading…</div>
      ) : (
        <>
          {tab === 'users' && <UsersSection users={users} roles={roles} onChange={setUsers} />}
          {tab === 'roles' && <RolesSection roles={roles} groups={groups} onChange={setRoles} />}
          {tab === 'permissions' && <PermissionsSection groups={groups} />}
        </>
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
  const [editing, setEditing] = useState<ShopUser | null>(null);
  const [creating, setCreating] = useState(false);

  const upsert = (u: ShopUser) => {
    const exists = users.some((x) => x.id === u.id);
    onChange(exists ? users.map((x) => (x.id === u.id ? u : x)) : [...users, u]);
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
      <div className="ac-section-head">
        <span className="ac-section-title">{users.length} user{users.length === 1 ? '' : 's'}</span>
        <button className="c-btn" style={{ width: 'auto', padding: '8px 14px' }} onClick={() => setCreating(true)}>
          <Icons.Plus size={16} /> Add user
        </button>
      </div>

      {users.length === 0 && <div className="ac-empty">No users yet. Add your first team member.</div>}

      {users.map((u) => (
        <div className="ac-row" key={u.id}>
          <div className="ac-row-main">
            <div className="ac-row-title">
              {u.name}
              {currentUser?.id === u.id && <span className="ac-badge ac-badge-muted">You</span>}
            </div>
            <div className="ac-row-sub">
              {u.role ? <span className="ac-badge">{u.role.name}</span> : <span className="ac-badge ac-badge-muted">No role</span>}
              {!u.is_active && <span className="ac-badge ac-badge-off" style={{ marginLeft: 6 }}>Inactive</span>}
            </div>
          </div>
          <div className="ac-actions">
            <button className="ac-icon-btn" aria-label="Edit" onClick={() => setEditing(u)}>
              <Icons.Edit size={16} />
            </button>
            <button className="ac-icon-btn ac-icon-btn-danger" aria-label="Delete" onClick={() => void remove(u)}>
              <Icons.Trash size={16} />
            </button>
          </div>
        </div>
      ))}

      {(creating || editing) && (
        <UserModal
          user={editing}
          roles={roles}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSaved={(u) => { upsert(u); setCreating(false); setEditing(null); }}
        />
      )}
    </>
  );
}

function UserModal({
  user,
  roles,
  onClose,
  onSaved,
}: {
  user: ShopUser | null;
  roles: Role[];
  onClose: () => void;
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
    <div className="ac-backdrop" onClick={onClose}>
      <div className="ac-modal" onClick={(e) => e.stopPropagation()}>
        <div className="ac-modal-title">{isEdit ? 'Edit user' : 'Add user'}</div>
        <div className="ac-modal-sub">Users log in with the Business ID and their own PIN.</div>

        {error && <div className="ac-error">{error}</div>}

        <div className="ac-field">
          <label className="ac-field-label">Name</label>
          <input className="ac-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Sara" />
        </div>

        <div className="ac-field">
          <label className="ac-field-label">{isEdit ? 'PIN (leave blank to keep)' : 'PIN'}</label>
          <input
            className="ac-input"
            inputMode="numeric"
            value={pin}
            onChange={(e) => setPin(e.target.value)}
            placeholder={isEdit ? '••••' : 'Choose a login PIN'}
          />
        </div>

        <div className="ac-field">
          <label className="ac-field-label">Role</label>
          <select className="ac-select" value={roleId ?? ''} onChange={(e) => setRoleId(e.target.value ? Number(e.target.value) : null)}>
            <option value="">No role</option>
            {roles.map((r) => (
              <option key={r.id} value={r.id}>{r.name}</option>
            ))}
          </select>
        </div>

        <label className="ac-check-row">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
          Active (can log in)
        </label>

        <div className="ac-modal-actions">
          <button className="c-btn c-btn-ghost" onClick={onClose}>Cancel</button>
          <button className="c-btn" disabled={saving} onClick={() => void save()}>
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
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
  const [editing, setEditing] = useState<Role | null>(null);
  const [creating, setCreating] = useState(false);

  const upsert = (r: Role) => {
    const exists = roles.some((x) => x.id === r.id);
    onChange(exists ? roles.map((x) => (x.id === r.id ? r : x)) : [...roles, r]);
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
      <div className="ac-section-head">
        <span className="ac-section-title">{roles.length} role{roles.length === 1 ? '' : 's'}</span>
        <button className="c-btn" style={{ width: 'auto', padding: '8px 14px' }} onClick={() => setCreating(true)}>
          <Icons.Plus size={16} /> Add role
        </button>
      </div>

      {roles.map((r) => (
        <div className="ac-row" key={r.id}>
          <div className="ac-row-main">
            <div className="ac-row-title">
              {r.name}
              {r.is_owner && <span className="ac-badge ac-badge-muted"><Icons.Key size={12} /> Owner</span>}
            </div>
            <div className="ac-row-sub">
              {r.is_owner ? 'Full access — cannot be changed' : `${r.permissions.length} permission${r.permissions.length === 1 ? '' : 's'}`}
            </div>
          </div>
          <div className="ac-actions">
            <button className="ac-icon-btn" aria-label="Edit" disabled={r.is_owner} onClick={() => setEditing(r)}>
              <Icons.Edit size={16} />
            </button>
            <button className="ac-icon-btn ac-icon-btn-danger" aria-label="Delete" disabled={r.is_owner} onClick={() => void remove(r)}>
              <Icons.Trash size={16} />
            </button>
          </div>
        </div>
      ))}

      {(creating || editing) && (
        <RoleModal
          role={editing}
          groups={groups}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSaved={(r) => { upsert(r); setCreating(false); setEditing(null); }}
        />
      )}
    </>
  );
}

function RoleModal({
  role,
  groups,
  onClose,
  onSaved,
}: {
  role: Role | null;
  groups: Record<string, PermGroup>;
  onClose: () => void;
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
      next.has(perm) ? next.delete(perm) : next.add(perm);
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
    <div className="ac-backdrop" onClick={onClose}>
      <div className="ac-modal" onClick={(e) => e.stopPropagation()}>
        <div className="ac-modal-title">{isEdit ? 'Edit role' : 'Add role'}</div>
        <div className="ac-modal-sub">Choose which actions this role can perform.</div>

        {error && <div className="ac-error">{error}</div>}

        <div className="ac-field">
          <label className="ac-field-label">Role name</label>
          <input className="ac-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Receptionist" />
        </div>

        {Object.entries(groups).map(([key, group]) => {
          const perms = Object.keys(group.permissions);
          const allOn = perms.every((p) => selected.has(p));
          return (
            <div className="ac-matrix-group" key={key}>
              <div className="ac-matrix-head" onClick={() => toggleGroup(perms, allOn)}>
                <span className="ac-matrix-head-title">{group.label}</span>
                <span className="ac-link-btn">{allOn ? 'Clear all' : 'Select all'}</span>
              </div>
              <div className="ac-matrix-body">
                {Object.entries(group.permissions).map(([perm, label]) => (
                  <label className="ac-perm-row" key={perm}>
                    <input type="checkbox" checked={selected.has(perm)} onChange={() => toggle(perm)} />
                    <span>
                      {label}
                      <br />
                      <span className="ac-perm-name">{perm}</span>
                    </span>
                  </label>
                ))}
              </div>
            </div>
          );
        })}

        <div className="ac-modal-actions">
          <button className="c-btn c-btn-ghost" onClick={onClose}>Cancel</button>
          <button className="c-btn" disabled={saving} onClick={() => void save()}>
            {saving ? 'Saving…' : 'Save role'}
          </button>
        </div>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Permissions (read-only reference)                                          */
/* -------------------------------------------------------------------------- */

function PermissionsSection({ groups }: { groups: Record<string, PermGroup> }) {
  const entries = useMemo(() => Object.entries(groups), [groups]);

  if (entries.length === 0) {
    return <div className="ac-empty">No permissions defined.</div>;
  }

  return (
    <>
      {entries.map(([key, group]) => (
        <div className="ac-ref-group" key={key}>
          <div className="ac-ref-group-title">{group.label}</div>
          {Object.entries(group.permissions).map(([perm, label]) => (
            <div className="ac-ref-item" key={perm}>
              <span>{label}</span>
              <span className="ac-perm-name">{perm}</span>
            </div>
          ))}
        </div>
      ))}
    </>
  );
}
