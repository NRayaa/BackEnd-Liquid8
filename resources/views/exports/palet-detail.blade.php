<!DOCTYPE html>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        @page {
            size: A4 landscape;
            /* Mengatur halaman menjadi A4 dengan orientasi landscape */
            margin: 10mm;
            /* Memperkecil margin untuk memaksimalkan ruang konten */
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 10px;
            /* Menambahkan padding kecil untuk estetika */
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            /* Ukuran font tabel diperkecil untuk memuat lebih banyak data */
        }

        th,
        td {
            border: 1px solid black;
            padding: 4px;
            /* Padding lebih kecil untuk menghemat ruang */
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
            /* Memberikan sedikit warna latar untuk header tabel */
        }

        h3 {
            margin-top: 10px;
            /* Jarak antar heading dikurangi */
            margin-bottom: 5px;
        }

        p {
            margin: 2px 0;
            /* Mengurangi margin pada paragraf */
        }
    </style>
</head>

<body>
    <h3>Palet Details</h3>
    <p><strong>Nama Palet:</strong> {{ $palet->name_palet }}</p>
    <p><strong>Qty :</strong> {{ $palet->total_product_palet }}</p>
    <p><strong>Harga Lama: </strong>Rp.
        {{ number_format($palet->paletProducts->sum('old_price_product'), 0, ',', '.') ?? 'Tidak ada value' }}</p>
    <p><strong>Diskon (%) :</strong> {{ number_format($palet->discount, 0) }}</p>
    <p><strong>Harga Baru: </strong>Rp. {{ number_format($palet->total_price_palet, 0, ',', '.') }}</p>
    <p><strong>Barcode:</strong> {{ $palet->palet_barcode }}</p>

    {{-- <p><strong>Kategori:</strong> {{ $palet->category_palet }}</p> --}}
    {{-- <p><strong>Warehouse:</strong> {{ $palet->warehouse_name }}</p> --}}
    {{-- <p><strong>Kondisi Produk:</strong> {{ $palet->product_condition_name }}</p> --}}
    {{-- <p><strong>Status Produk:</strong> {{ $palet->product_status_name }}</p> --}}

    <h3>Product List</h3>
    <table>
        <tr>
            <th>Nama</th>
            <th>Qty</th>
            <th>Harga Lama</th>
            <th>Diskon (%) </th>
            <th>Harga Baru</th>
            <th>Barcode</th>
            {{-- <th>Tgl masuk</th> --}}
            {{-- <th>Kategoi</th> --}}
            {{-- <th>Tag Warna</th> --}}
        </tr>
        @foreach ($palet->paletProducts as $product)
            <tr>
                <td>{{ $product->new_name_product }}</td>
                <td>{{ $product->new_quantity_product }}</td>
                <td>{{ $product->old_price_product }}</td>
                <td>{{ number_format($product->new_discount, 0) }}</td>
                <td>{{ $product->new_price_product }}</td>
                <td>{{ $product->new_barcode_product }}</td>
                {{-- <td>{{ $product->new_date_in_product }}</td> --}}
                {{-- <td>{{ $product->new_category_product }}</td> --}}
                {{-- <td>{{ $product->new_tag_product }}</td> --}}
            </tr>
        @endforeach
    </table>
</body>

</html>
