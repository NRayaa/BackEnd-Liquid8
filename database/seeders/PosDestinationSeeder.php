<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Destination;
use App\Services\Pos\PosService;
use Illuminate\Support\Facades\Log;

class PosDestinationSeeder extends Seeder
{
    public function run()
    {
        $this->command->info('Memulai sinkronisasi Destinasi POS...');

        // 1. Soft Delete semua data destinasi lama
        $deletedCount = Destination::query()->delete();
        $this->command->info("Berhasil melakukan Soft Delete pada {$deletedCount} destinasi lama.");

        // 2. Ambil data dari API POS
        $this->command->info('Mengambil data toko terbaru dari POS API...');

        try {
            $posService = new PosService();
            $response = $posService->getStores();

            if (isset($response['status']) && $response['status'] == true) {

                $stores = $response['resource'];
                $syncedCount = 0;

                foreach ($stores as $store) {
                    Destination::create([
                        'shop_name'    => $store['store_name'],
                        'pos_token'    => $store['token'],
                        'phone_number' => $store['phone'] ?? '08',
                        'alamat'       => $store['address'] ?? $store['store_name'],
                    ]);
                    $syncedCount++;
                }

                $this->command->info("Berhasil menyinkronkan {$syncedCount} toko baru dari POS!");
            } else {
                $this->command->error('Gagal menyinkronkan: Format response POS tidak sesuai.');
                Log::error('Seeder Error: Response POS -> ', $response);
            }
        } catch (\Exception $e) {
            $this->command->error('Terjadi kesalahan koneksi ke POS API: ' . $e->getMessage());
        }
    }
}
