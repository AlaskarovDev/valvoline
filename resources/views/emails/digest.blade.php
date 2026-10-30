@php $d = $digest; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Gündəlik xülasə') }}</title></head>
<body style="margin:0; padding:24px 12px; background:#f4f6f9; font-family:-apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:12px; border:1px solid #e5e7eb;">
    <tr><td style="padding:20px 24px; border-bottom:1px solid #e5e7eb;">
        <div style="font-size:16px; font-weight:600;">{{ config('app.name') }}</div>
        <div style="font-size:13px; color:#6b7280;">{{ __('Salam, :name! :date üzrə xülasə.', ['name' => $d['user'], 'date' => $d['date']]) }}</div>
    </td></tr>
    @if ($d['kpis'])
        <tr><td style="padding:16px 24px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                @foreach ($d['kpis'] as $k)
                    <tr>
                        <td style="padding:6px 0; font-size:14px; color:#6b7280;">{{ $k['label'] }}</td>
                        <td style="padding:6px 0; font-size:14px; font-weight:600; text-align:right; white-space:nowrap;">{{ $k['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </td></tr>
    @endif
    <tr><td style="padding:8px 24px 16px;">
        <div style="font-size:14px; font-weight:600; margin-bottom:8px;">{{ __('Diqqət tələb edir') }}</div>
        @forelse ($d['alerts'] as $a)
            <div style="padding:10px 12px; margin-bottom:8px; border-radius:8px; border-left:4px solid {{ $a['level'] === 'danger' ? '#dc2626' : '#d97706' }}; background:#f9fafb;">
                <a href="{{ $a['url'] }}" style="font-size:14px; font-weight:600; color:#1f2937; text-decoration:none;">{{ $a['title'] }}</a>
                <div style="font-size:13px; color:#6b7280;">{{ $a['detail'] }}</div>
            </div>
        @empty
            <div style="font-size:13px; color:#6b7280;">{{ __('Diqqət tələb edən heç nə yoxdur.') }}</div>
        @endforelse
    </td></tr>
    <tr><td style="padding:16px 24px; border-top:1px solid #e5e7eb;">
        <a href="{{ $d['url'] }}" style="display:inline-block; padding:10px 16px; border-radius:8px; background:#1e3a5f; color:#ffffff; font-size:14px; text-decoration:none;">{{ __('Sistemi aç') }}</a>
        <div style="margin-top:12px; font-size:12px; color:#9ca3af;">{{ __('Bu məktubu «Hesabım» səhifəsində söndürə bilərsiniz.') }}</div>
    </td></tr>
</table>
</body>
</html>
