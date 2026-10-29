    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f3; color: #111; font: 12px/1.45 "Segoe UI", Arial, sans-serif; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm 12mm; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .12); }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 12px; }
        .toolbar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #c8ccd2; background: #fff; cursor: pointer; }
        .toolbar button.primary { background: #1d3b6a; border-color: #1d3b6a; color: #fff; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        header .company { font-size: 16px; font-weight: 700; }
        header .muted, .muted { color: #555; }
        h1 { margin: 18px 0 2px; font-size: 20px; text-align: center; letter-spacing: .02em; }
        .doc-meta { text-align: center; margin-bottom: 16px; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px; }
        .party { border: 1px solid #c8ccd2; border-radius: 6px; padding: 8px 10px; }
        .party .label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: 2px; }
        .party .name { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9aa0a8; padding: 5px 6px; vertical-align: top; }
        th { background: #f1f3f5; font-size: 11px; text-align: left; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .sub { font-size: 10.5px; color: #444; margin-top: 2px; }
        tfoot td { font-weight: 700; background: #f8f9fa; }
        .totals { margin-left: auto; margin-top: 10px; width: 280px; }
        .totals td { border: none; padding: 2px 0; }
        .totals tr.grand td { border-top: 2px solid #111; padding-top: 4px; font-size: 14px; font-weight: 700; }
        .note { margin-top: 12px; }
        .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 46px; }
        .signs-3 { grid-template-columns: 1fr 1fr 1fr; gap: 24px; }
        .signs div { border-top: 1px solid #111; padding-top: 4px; font-size: 11px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        }
    </style>
