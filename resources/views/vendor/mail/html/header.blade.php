@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: inherit;">
<img src="{{ asset('images/logo.png') }}" class="logo" alt="Logo" style="height: 35px; width: auto; vertical-align: middle;">
<span>{{ $slot }}</span>
</a>
</td>
</tr>
