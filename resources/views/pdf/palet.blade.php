<!DOCTYPE html>
<html>

<head>
    <title>Palet Data</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
            /* Agar lebar kolom tetap stabil */
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            word-wrap: break-word;
            /* Membungkus teks jika terlalu panjang */
        }

        th {
            background-color: #f4f4f4;
        }

        td:nth-child(1) {
            width: 100ch;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        td:nth-child(2),
        td:nth-child(3),
        td:nth-child(4),
        td:nth-child(5),
        td:nth-child(6) {
            text-align: right;
            /* Agar nilai di kolom lain rata kanan */
        }
    </style>

</head>

<body>
    <h1>{{ $palet->name_palet }}</h1>
    <p><strong>Qty Product:</strong> {{ $palet->total_product_palet ?? 'Tidak ada product' }}</p>
    <p><strong>Harga Lama: </strong>Rp.
        {{ number_format($palet->paletProducts->sum('old_price_product'), 0, ',', '.') ?? 'Tidak ada value' }}</p>
    <p><strong>Diskon (%) :</strong> {{ number_format($palet->discount, 0) ?? 'Tidak ada value' }}</p>
    <p><strong>Harga Baru: </strong>Rp. {{ number_format($palet->total_price_palet, 0, ',', '.') }}</p>
    <p><strong>Barcode Palet:</strong> {{ $palet->palet_barcode ?? 'Tidak ada barcode' }}</p>

    <h2>Daftar Produk</h2>
    <table>
        <thead>
            <tr>
                <th>Nama Produk</th>
                <th>Jumlah</th>
                <th>Harga Lama</th>
                <th>Diskon (%)</th>
                <th>Harga Baru</th>
                <th>Barcode Produk</th>
                {{-- <th>Kategori Produk</th> --}}
                {{-- <th>Tag Produk</th> --}}
            </tr>
        </thead>
        <tbody>
            @foreach ($palet->paletProducts as $product)
                <tr>
                    <td>{{ $product->new_name_product }}</td>
                    <td>{{ $product->new_quantity_product }}</td>
                    <td>{{ number_format($product->old_price_product, 2) }}</td>
                    <td>{{ number_format($product->new_discount, 0) ?? 0 }}%</td>
                    <td>{{ number_format($product->new_price_product, 2) }}</td>
                    <td>{{ $product->new_barcode_product }}</td>
                    {{-- <td>{{ $product->new_category_product }}</td> --}}
                    {{-- <td>{{ $product->new_tag_product ?? 'Tidak ada tag' }}</td> --}}
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
