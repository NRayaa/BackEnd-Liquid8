<!DOCTYPE html>
<html>
<head>
    <title>Palet Data</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
    </style>
</head>
<body>
    <h1>{{ $palet->name_palet }}</h1>
    <p><strong>Barcode Palet:</strong> {{ $palet->palet_barcode ?? 'Tidak ada barcode' }}</p>
    <p><strong>Kategori:</strong> {{ $palet->category_palet }}</p>
    <p><strong>Total Harga:</strong> {{ number_format($palet->total_price_palet, 2) }}</p>
    <p><strong>Deskripsi:</strong> {{ $palet->description ?? 'Tidak ada deskripsi' }}</p>
    <p><strong>Qty Product:</strong> {{ $palet->total_product_palet ?? 'Tidak ada product' }}</p>
    <p><strong>Sale:</strong> 
        @if ($palet->is_sale == 1)
            sudah terjual
        @else
            belum terjual
        @endif
    </p>
    <p><strong>Warehouse:</strong> {{ $palet->warehouse_name ?? 'Tidak ada value' }}</p>
    <p><strong>Kondisi Product:</strong> {{ $palet->product_condition_name ?? 'Tidak ada value' }}</p>
    <p><strong>Status Product:</strong> {{ $palet->product_status_name ?? 'Tidak ada value' }}</p>
    <p><strong>Diskon :</strong> {{ $palet->discount ?? 'Tidak ada value' }}</p>
    <p><strong>Harga Lama :</strong> {{ number_format($palet->paletProducts->sum('old_price_product'), 2) ?? 'Tidak ada value' }}</p>

    <h2>Daftar Produk</h2>
    <table>
        <thead>
            <tr>
                <th>Nama Produk</th>
                <th>Barcode Produk</th>
                <th>Jumlah</th>
                <th>Harga Baru</th>
                <th>Harga Lama</th>
                <th>Kategori Produk</th>
                <th>Tag Produk</th>
                <th>Diskon</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($palet->paletProducts as $product)
            <tr>
                <td>{{ $product->new_name_product }}</td>
                <td>{{ $product->new_barcode_product }}</td>
                <td>{{ $product->new_quantity_product }}</td>
                <td>{{ number_format($product->new_price_product, 2) }}</td>
                <td>{{ number_format($product->old_price_product, 2) }}</td>
                <td>{{ $product->new_category_product }}</td>
                <td>{{ $product->new_tag_product ?? 'Tidak ada tag' }}</td>
                <td>{{ $product->new_discount ?? 0 }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
