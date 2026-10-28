<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Pengantar {{ $laporan->no_laporan }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.6;
        }

        h1 {
            font-size: 18px;
            text-align: center;
            text-transform: uppercase;
        }

        .meta {
            margin: 24px 0;
        }

        .signature {
            margin-top: 56px;
            text-align: right;
        }
    </style>
</head>
<body>
    <h1>Surat Pengantar Resmi</h1>

    <p>Nomor: {{ $laporan->no_laporan }}</p>
    <p>Tanggal: {{ $tanggalEskalasi?->format('d-m-Y H:i') }}</p>

    <div class="meta">
        <p>Kepada Yth. {{ $tujuan }}</p>
        <p>Perihal: Pengantar laporan untuk ditindaklanjuti</p>
    </div>

    <p>Dengan hormat,</p>
    <p>
        Bersama surat ini kami meneruskan laporan dari {{ $namaSantri }}
        untuk mendapatkan tindak lanjut.
    </p>

    <p><strong>Kategori:</strong> {{ $laporan->kategori }}</p>
    <p><strong>Isi laporan:</strong></p>
    <p>{{ $laporan->isi }}</p>
    <p><strong>Catatan pengurus:</strong></p>
    <p>{{ $catatanPengurus }}</p>

    <div class="signature">
        <p>Hormat kami,</p>
        <p>Pengurus Sahabat Pesantren</p>
        <p>{{ $namaPengurus }}</p>
    </div>
</body>
</html>
