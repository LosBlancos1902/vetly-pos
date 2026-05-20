import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';

interface PermissionRow {
    id: number;
    name: string;
}
interface RoleRow {
    id: number;
    name: string;
    permission_names: string[];
}
interface Props {
    roles: RoleRow[];
    permissionGroups: Record<string, PermissionRow[]>;
}

export default function Roles({ roles, permissionGroups }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const [draft, setDraft] = useState<Set<string>>(new Set());

    function startEdit(role: RoleRow) {
        setEditing(role.id);
        setDraft(new Set(role.permission_names));
    }

    function togglePerm(name: string) {
        const next = new Set(draft);
        if (next.has(name)) next.delete(name);
        else next.add(name);
        setDraft(next);
    }

    function save(role: RoleRow) {
        router.put(
            route('settings.roles.update', role.id),
            { permissions: Array.from(draft) },
            {
                onSuccess: () => {
                    toast.success(`Role '${role.name}' diperbarui`);
                    setEditing(null);
                },
                onError: () => toast.error('Gagal menyimpan'),
                preserveScroll: true,
            },
        );
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Role & Permission</h2>}
        >
            <Head title="Roles" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <p className="text-sm text-muted-foreground">
                    Role adalah template permission. Pilih permission untuk setiap role; user
                    yang punya role tersebut otomatis dapat akses-nya. Penambahan role baru
                    akan dibangun di fase berikutnya.
                </p>

                {roles.map((role) => {
                    const isEditing = editing === role.id;
                    const activeSet = isEditing ? draft : new Set(role.permission_names);

                    return (
                        <Card key={role.id}>
                            <CardContent className="space-y-3 p-6">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-lg font-semibold capitalize">
                                        {role.name}
                                        <Badge variant="muted" className="ml-2">
                                            {role.permission_names.length} permission
                                        </Badge>
                                    </h3>
                                    {isEditing ? (
                                        <div className="flex gap-2">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setEditing(null)}
                                            >
                                                Batal
                                            </Button>
                                            <Button size="sm" onClick={() => save(role)}>
                                                Simpan
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => startEdit(role)}
                                        >
                                            Edit
                                        </Button>
                                    )}
                                </div>

                                {Object.entries(permissionGroups).map(([group, perms]) => (
                                    <div key={group}>
                                        <h4 className="mb-1 text-sm font-medium uppercase text-muted-foreground">
                                            {group}
                                        </h4>
                                        <div className="grid grid-cols-1 gap-1 md:grid-cols-2 lg:grid-cols-3">
                                            {perms.map((perm) => {
                                                const checked = activeSet.has(perm.name);
                                                return (
                                                    <label
                                                        key={perm.id}
                                                        className={`flex items-center gap-2 rounded-md px-2 py-1 text-sm ${
                                                            isEditing
                                                                ? 'cursor-pointer hover:bg-muted'
                                                                : 'cursor-default'
                                                        }`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            disabled={!isEditing}
                                                            onChange={() => togglePerm(perm.name)}
                                                        />
                                                        <span
                                                            className={
                                                                checked
                                                                    ? 'font-medium'
                                                                    : 'text-muted-foreground'
                                                            }
                                                        >
                                                            {perm.name}
                                                        </span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
