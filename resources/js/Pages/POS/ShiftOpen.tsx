import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/** Shift-open form. Mount as a modal/page before the cashier can sell. */
export default function ShiftOpen({ warehouseId }: { warehouseId: number }) {
    const { data, setData, post, processing } = useForm({
        warehouse_id: warehouseId,
        opening_cash: 0,
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                post(route('pos.shifts.open'));
            }}
            className="mx-auto max-w-md space-y-4 p-6"
        >
            <h2 className="text-xl font-semibold">Buka Shift</h2>
            <div>
                <Label htmlFor="opening_cash">Kas Awal</Label>
                <Input
                    id="opening_cash"
                    type="number"
                    value={data.opening_cash}
                    onChange={(e) => setData('opening_cash', Number(e.target.value))}
                />
            </div>
            <Button size="lg" className="w-full" disabled={processing}>
                Buka Shift
            </Button>
        </form>
    );
}
