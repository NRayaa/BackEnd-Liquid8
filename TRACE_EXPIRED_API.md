# Trace Expired History API Documentation

## Endpoint
**POST** `/api/traceExpired`

## Description
Function untuk menghitung dan menampilkan detail expired untuk semua transaksi buyer, termasuk:
- Status expired atau tidak untuk setiap transaksi
- Count progression (before → after)
- Expire_date pada saat transaksi
- Rank yang berlaku
- Total expired events

## Request Parameters

### Required
- `buyer_id` (integer) - ID buyer yang akan di-trace

### Optional
- `sale_document_id` (integer) - ID sale document tertentu untuk trace sampai transaksi tersebut saja

## Request Example

### Trace All Transactions
```json
POST /api/traceExpired
Content-Type: application/json

{
  "buyer_id": 1
}
```

### Trace Until Specific Transaction
```json
POST /api/traceExpired
Content-Type: application/json

{
  "buyer_id": 1,
  "sale_document_id": 10
}
```

## Response Example

```json
{
  "success": true,
  "message": "Trace expired history untuk buyer Miss Darlene Botsford",
  "data": {
    "buyer_id": 1,
    "total_transactions": 11,
    "transactions": [
      {
        "transaction_number": 1,
        "sale_document_id": 14,
        "code_document_sale": "LQDSLE00013",
        "transaction_date": "02 Jun 2025 16:29:17",
        "total_display": 5000000,
        "count_before": 0,
        "count_after": 1,
        "expired_status": "VALID",
        "expire_date_before": null,
        "rank_after": "Bronze",
        "expired_weeks": 5,
        "expire_date_action": "NONE",
        "expire_date_after": null
      },
      {
        "transaction_number": 2,
        "sale_document_id": 13,
        "code_document_sale": "LQDSLE00012",
        "transaction_date": "09 Jun 2025 16:29:02",
        "total_display": 5000000,
        "count_before": 1,
        "count_after": 2,
        "expired_status": "VALID",
        "expire_date_before": null,
        "rank_after": "Bronze",
        "expired_weeks": 5,
        "expire_date_action": "NEW",
        "expire_date_after": "14 Jul 2025 23:59:59"
      },
      {
        "transaction_number": 5,
        "sale_document_id": 10,
        "code_document_sale": "LQDSLE00009",
        "transaction_date": "24 Sep 2025 15:52:14",
        "total_display": 5000000,
        "count_before": 4,
        "count_after": 1,
        "expired_status": "EXPIRED",
        "expired_reason": "Transaction date (24 Sep 2025) > Expire date (08 Sep 2025)",
        "expire_date_before": "08 Sep 2025 23:59:59",
        "rank_after": "Bronze",
        "expired_weeks": 5,
        "expire_date_action": "NONE",
        "expire_date_after": null
      }
    ],
    "summary": {
      "total_expired_events": 1,
      "final_count": 7,
      "final_rank": "Gold",
      "final_expire_date": "11 Mar 2026 23:59:59"
    }
  }
}
```

## Response Fields

### Transaction Fields
- `transaction_number`: Nomor urut transaksi
- `sale_document_id`: ID dokumen penjualan
- `code_document_sale`: Kode dokumen penjualan
- `transaction_date`: Tanggal transaksi (format: d M Y H:i:s)
- `total_display`: Total harga display
- `count_before`: Jumlah transaksi sebelum transaksi ini diproses
- `count_after`: Jumlah transaksi setelah transaksi ini diproses
- `expired_status`: Status expired (`VALID` atau `EXPIRED`)
- `expired_reason`: Alasan expired (jika status = EXPIRED)
- `expire_date_before`: Expire date sebelum transaksi ini diproses
- `rank_after`: Rank setelah transaksi ini diproses
- `expired_weeks`: Jumlah minggu expired untuk rank tersebut
- `expire_date_action`: Aksi pada expire_date (`NONE`, `NEW`, atau `UPDATE`)
- `expire_date_calculation`: Perhitungan expire_date (jika action = UPDATE)
- `expire_date_after`: Expire date setelah transaksi ini diproses

### Summary Fields
- `total_expired_events`: Total kejadian expired
- `final_count`: Jumlah transaksi akhir (setelah expired check)
- `final_rank`: Rank akhir buyer
- `final_expire_date`: Expire date akhir

## Use Cases

### 1. Debug Buyer Loyalty
Untuk mengecek apakah perhitungan expired sudah benar untuk buyer tertentu:
```bash
curl -X POST http://localhost:8000/api/traceExpired \
  -H "Content-Type: application/json" \
  -d '{"buyer_id": 1}'
```

### 2. Check Specific Transaction
Untuk mengecek status buyer pada transaksi tertentu:
```bash
curl -X POST http://localhost:8000/api/traceExpired \
  -H "Content-Type: application/json" \
  -d '{"buyer_id": 1, "sale_document_id": 10}'
```

### 3. Validate Expiration Logic
Untuk memvalidasi bahwa expiration terjadi di transaksi yang benar:
- Cek field `expired_status` = "EXPIRED"
- Cek field `expired_reason` untuk detail

## Example Output Analysis

Dari output di atas, kita bisa lihat:

1. **Trans #1-4**: Build up dari count 0→1→2→3→4
   - Expire_date mulai di-set di Trans #2 (14 Jul 2025)
   - Expire_date ter-update di Trans #3 (11 Aug 2025)
   - Expire_date ter-update di Trans #4 (08 Sep 2025)

2. **Trans #5**: **EXPIRED!** ⚠️
   - Trans date: 24 Sep 2025
   - Expire date: 08 Sep 2025
   - Count reset: 4 → 1
   - Rank reset: Bronze

3. **Trans #6-11**: Rebuild dari count 1→7
   - Expire_date baru mulai di Trans #6 (05 Nov 2025)
   - Final count: 7 (bukan 11 karena ada expired)

## Error Responses

### Buyer Not Found
```json
{
  "success": false,
  "message": "Buyer dengan ID 999 tidak ditemukan!",
  "data": null
}
```
Status Code: 404

### Validation Error
```json
{
  "success": false,
  "message": "The buyer id field is required.",
  "data": {
    "buyer_id": ["The buyer id field is required."]
  }
}
```
Status Code: 422

## Notes

- Function ini **read-only**, tidak mengubah data di database
- Bisa digunakan untuk debugging atau validasi perhitungan expired
- Cocok untuk cross-check dengan data di buyer_loyalties table
- Menampilkan **historical progression** dari setiap transaksi
