@props(['url'])
@php
    $ownerCompany = \App\Models\OwnerCompany::first();
    $logoPath = null;
    if ($ownerCompany && !empty($ownerCompany->logo) && file_exists(public_path($ownerCompany->logo))) {
        $logoPath = public_path($ownerCompany->logo);
    } elseif (file_exists(public_path('images/logo.png'))) {
        $logoPath = public_path('images/logo.png');
    }
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: inherit;">
    @if ($logoPath)
        <img src="cid:logo@laracollab" class="logo" alt="{{ config('app.name', 'LaraCollab') }}" style="max-height: 45px; width: auto; vertical-align: middle;">
    @endif
    <span>{{ $slot }}</span>
</a>
</td>
</tr>
