@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; color: #0C5295;">
{!! $slot !!}
</a>
</td>
</tr>
