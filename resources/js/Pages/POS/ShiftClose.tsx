import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { rupiah } from '@/lib/utils';

export default function ShiftClose({
    shiftId,
    expectedCash,
}: {
    shiftId: number;
    expectedCash: number;
}) {
    const { data, setData, post, processing } = useForm({
        shift_id: shiftId,
        expected_cash: expectedCash,
        closing_cash: 0,
    });

    const variance = data.closing_cash - expectedCash;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                post(route('pos.shifts.close'));
            }}
            className="mx-auto max-w-md space-y-4 p-6"
        >
            <h2 className="text-xl font-semibold">Tutup Shift</h2>
            <p className="text-muted-foreground">Kas seharusnya: {rupiah(expectedCash)}</p>
            <div>
                <Label htmlFor="closing_cash">Kas Aktual</Label>
                <Input
                    id="closing_cash"
                    type="number"
                    value={data.closing_cash}
                    onChange={(e) => setData('closing_cash', Number(e.target.value))}
                />
            </div>
            <p className={variance < 0 ? 'text-destructive' : ''}>
                Selisih: {rupiah(variance)}
            </p>
            <Button size="lg" className="w-full" disabled={processing}>
                Tutup Shift
            </Button>
        </form>
    );
}
