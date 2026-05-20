import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { rupiah } from '@/lib/utils';

export default function Journal({ journals }: { journals: { data: any[] } }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Jurnal</h2>}>
            <Head title="Jurnal" />
            <div className="mx-auto max-w-4xl space-y-4 p-4">
                {journals.data.map((j) => (
                    <Card key={j.id}>
                        <CardHeader>
                            <CardTitle className="text-lg">
                                {j.journal_no} — {j.description}
                            </CardTitle>
                            <div className="text-sm text-muted-foreground">
                                {j.date} · {j.status}
                            </div>
                        </CardHeader>
                        <CardContent>
                            {j.entries?.map((e: any) => (
                                <div key={e.id} className="flex justify-between text-sm">
                                    <span>
                                        {e.coa?.code} {e.coa?.name}
                                    </span>
                                    <span>
                                        D {rupiah(e.debit)} / K {rupiah(e.credit)}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
