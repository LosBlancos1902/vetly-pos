import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Card, CardContent } from '@/Components/ui/card';

export default function Tenant({
    tenant,
    users,
}: {
    tenant: { id: string } | null;
    users: any[];
}) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Pengaturan Tenant</h2>}
        >
            <Head title="Pengaturan Tenant" />
            <div className="mx-auto max-w-4xl space-y-4 p-4">
                <Card>
                    <CardContent className="p-6">
                        <div className="text-muted-foreground">Tenant ID</div>
                        <div className="text-lg font-semibold">{tenant?.id ?? '-'}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Aktif</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((u) => (
                                    <TableRow key={u.id}>
                                        <TableCell>{u.name}</TableCell>
                                        <TableCell>{u.email}</TableCell>
                                        <TableCell>
                                            {u.roles?.map((r: any) => r.name).join(', ')}
                                        </TableCell>
                                        <TableCell>{u.is_active ? 'Ya' : 'Tidak'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
