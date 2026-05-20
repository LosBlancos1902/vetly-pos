/**
 * Web Bluetooth thermal-printer helper.
 *
 * Most generic ESC/POS BLE printers expose the Nordic UART-style service.
 * connectPrinter() lets the user pick a device; printReceipt() streams an
 * ESC/POS byte string in <=512B chunks via writeValueWithoutResponse.
 */

// Common writable characteristics across cheap thermal printers.
const PRINT_SERVICE = 0x18f0;
const PRINT_CHARACTERISTIC = 0x2af1;

let characteristic: BluetoothRemoteGATTCharacteristic | null = null;

function assertSupported(): void {
    if (!('bluetooth' in navigator)) {
        throw new Error('Web Bluetooth tidak didukung di browser ini. Gunakan Chrome/Edge.');
    }
}

export async function connectPrinter(): Promise<string> {
    assertSupported();

    const device = await navigator.bluetooth.requestDevice({
        filters: [{ services: [PRINT_SERVICE] }],
        optionalServices: [PRINT_SERVICE],
    });

    const server = await device.gatt!.connect();
    const service = await server.getPrimaryService(PRINT_SERVICE);
    characteristic = await service.getCharacteristic(PRINT_CHARACTERISTIC);

    device.addEventListener('gattserverdisconnected', () => {
        characteristic = null;
    });

    return device.name ?? 'Printer';
}

export function isConnected(): boolean {
    return characteristic !== null;
}

/** Decode the base64 ESC/POS payload returned by the API and stream it. */
export async function printReceipt(escposBase64: string): Promise<void> {
    if (!characteristic) {
        throw new Error('Printer belum terhubung. Hubungkan printer terlebih dahulu.');
    }

    const binary = atob(escposBase64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    const CHUNK = 512;
    for (let offset = 0; offset < bytes.length; offset += CHUNK) {
        const slice = bytes.slice(offset, offset + CHUNK);
        await characteristic.writeValueWithoutResponse(slice);
        // Small delay so slow printers' buffers keep up.
        await new Promise((r) => setTimeout(r, 20));
    }
}
