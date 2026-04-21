<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Packing List - {{ $doc->name_document }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h2 {
            margin: 0;
            text-transform: uppercase;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 10px;
            color: #777;
        }
        .info-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-table td {
            vertical-align: top;
            padding: 3px 0;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 8px;
            margin-top: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th, .items-table td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            font-size: 10px;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .footer {
            margin-top: 30px;
            width: 100%;
        }
        .signature-box {
            width: 200px;
            float: right;
            text-align: center;
        }
        .signature-space {
            height: 80px;
        }
        .clear {
            clear: both;
        }
        .page-break {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>

    <div class="header">
        <h2>PACKING LIST - CARGO ONLINE</h2>
        <p>Dokumen Resmi Cargo</p>
    </div>

    <table class="info-table">
        <tr>
            <td width="15%"><strong>No. Dokumen</strong></td>
            <td width="35%">: {{ $doc->name_document }}</td>
            <td width="15%"><strong>Panjang</strong></td>
            <td width="35%">: {{ $doc->length ?? 0 }} cm</td>
        </tr>
        <tr>
            <td><strong>Tanggal</strong></td>
            <td>: {{ \Carbon\Carbon::parse($doc->created_at)->format('d-m-Y H:i') }}</td>
            <td><strong>Lebar</strong></td>
            <td>: {{ $doc->width ?? 0 }} cm</td>
        </tr>
        <tr>
            <td><strong>Admin/User</strong></td>
            <td>: {{ $doc->name_user }}</td>
            <td><strong>Tinggi</strong></td>
            <td>: {{ $doc->height ?? 0 }} cm</td>
        </tr>
        <tr>
            <td><strong>Tipe Kargo</strong></td>
            <td>: {{ strtoupper($doc->type) }}</td>
            <td><strong>Berat (Weight)</strong></td>
            <td>: {{ $doc->weight ?? 0 }} Kg</td>
        </tr>
        <tr>
            <td></td>
            <td></td>
            <td><strong>Volume</strong></td>
            <td>: {{ ($doc->length ?? 0) * ($doc->width ?? 0) * ($doc->height ?? 0) }} cm&sup3;</td>
        </tr>
    </table>

    @php
        $summaryBags = [];
        $summaryCategories = [];
        $totalQty = 0;
        $totalOldPrice = 0;
        $totalNewPrice = 0;

        foreach($doc->bulkySales as $item) {
            $bagId = $item->bag_product_id ?? 'none';
            $bagBarcode = $item->bagProduct->barcode_bag ?? '-'; 
            $bagName = $item->bagProduct->name_bag ?? '-';   

            if (!isset($summaryBags[$bagId])) {
                $summaryBags[$bagId] = [
                    'barcode' => $bagBarcode,
                    'name' => $bagName,
                    'qty' => 0,
                    'price' => 0,
                    'new_price' => 0 
                ];
            }
            $summaryBags[$bagId]['qty'] += 1;
            $summaryBags[$bagId]['price'] += $item->old_price_bulky_sale;
            $summaryBags[$bagId]['new_price'] += $item->after_price_bulky_sale; 

            $cat = $item->product_category_bulky_sale ?: 'Uncategorized';
            if (!isset($summaryCategories[$cat])) {
                $summaryCategories[$cat] = [
                    'qty' => 0,
                    'price' => 0,
                    'new_price' => 0 
                ];
            }
            
            $summaryCategories[$cat]['qty'] += 1;
            $summaryCategories[$cat]['price'] += $item->old_price_bulky_sale;
            $summaryCategories[$cat]['new_price'] += $item->after_price_bulky_sale;

            $totalQty += 1;
            $totalOldPrice += $item->old_price_bulky_sale;
            $totalNewPrice += $item->after_price_bulky_sale; 
        }
    @endphp

    <div class="page-break">
        <div class="section-title">Summary Bag</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th class="text-center" width="5%">NO</th>
                    <th width="20%">Barcode</th>
                    <th width="30%">Name Bag</th>
                    <th class="text-center" width="15%">Total Product</th>
                    <th class="text-right" width="15%">Sum of Old Price</th>
                    <th class="text-right" width="15%">Sum of New Price</th> 
                </tr>
            </thead>
            <tbody>
                @php $no = 1; @endphp
                @foreach($summaryBags as $bag)
                <tr>
                    <td class="text-center">{{ $no++ }}</td>
                    <td>{{ $bag['barcode'] }}</td>
                    <td>{{ strtoupper($bag['name']) }}</td>
                    <td class="text-center">{{ $bag['qty'] }}</td>
                    <td class="text-right">{{ number_format($bag['price'], 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($bag['new_price'], 0, ',', '.') }}</td> 
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-right">Grand Total</th>
                    <th class="text-center">{{ $totalQty }}</th>
                    <th class="text-right">{{ number_format($totalOldPrice, 0, ',', '.') }}</th>
                    <th class="text-right">{{ number_format($totalNewPrice, 0, ',', '.') }}</th> 
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="page-break">
        <div class="section-title">Summary Category</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th width="40%">CATEGORY</th>
                    <th class="text-center" width="20%">Total Product</th>
                    <th class="text-right" width="20%">Sum of Old Price</th>
                    <th class="text-right" width="20%">Sum of New Price</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summaryCategories as $catName => $cat)
                <tr>
                    <td>{{ strtoupper($catName) }}</td>
                    <td class="text-center">{{ $cat['qty'] }}</td>
                    <td class="text-right">{{ number_format($cat['price'], 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($cat['new_price'], 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th class="text-right">Grand Total</th>
                    <th class="text-center">{{ $totalQty }}</th>
                    <th class="text-right">{{ number_format($totalOldPrice, 0, ',', '.') }}</th>
                    <th class="text-right">{{ number_format($totalNewPrice, 0, ',', '.') }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="page-break">
        <div class="section-title">List Products</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th class="text-center" width="5%">No</th>
                    <th width="15%">Barcode Bulky Sale</th>
                    <th width="30%">Name Product Bulky Sale</th>
                    <th class="text-center" width="5%">Total Product</th>
                    <th class="text-right" width="15%">Old Price Bulky Sale</th>
                    <th class="text-center" width="10%">Discount (%)</th>
                    <th class="text-right" width="20%">Price After Discount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($doc->bulkySales as $index => $item)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $item->barcode_bulky_sale }}</td>
                    <td>{{ $item->name_product_bulky_sale }}</td>
                    <td class="text-center">1</td>
                    <td class="text-right">{{ number_format($item->old_price_bulky_sale, 0, ',', '.') }}</td>
                    <td class="text-center">{{ $doc->discount_bulky ?? 0 }}%</td>
                    <td class="text-right">{{ number_format($item->after_price_bulky_sale, 0, ',', '.') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center"><em>Tidak ada data produk.</em></td>
                </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-right">Grand Total</th>
                    <th class="text-center">{{ $totalQty }}</th> <th class="text-right">{{ number_format($doc->total_old_price_bulky, 0, ',', '.') }}</th>
                    <th class="text-center"></th>
                    <th class="text-right">{{ number_format($doc->after_price_bulky, 0, ',', '.') }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="footer">
        <div class="signature-box">
            <p>Disiapkan Oleh,</p>
            <div class="signature-space"></div>
            <p><strong>{{ $doc->name_user }}</strong></p>
            <p style="font-size: 10px; margin-top: -10px;">
                ({{ strtoupper($doc->user->role->role_name ?? 'Admin') }})
            </p>
        </div>
        <div class="clear"></div>
    </div>

</body>
</html>