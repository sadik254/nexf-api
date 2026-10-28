@php
    // Letter-spacing only between digits: on the last one it adds a selectable gap after the code.
    $codeHead = mb_substr($code, 0, -1);
    $codeTail = mb_substr($code, -1);
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px;">
    <tr>
        <td align="center" style="background:#fafafa;border:1px solid #e4e4e7;border-radius:10px;padding:20px 12px;">
            <div class="code" style="font-family:'Geist Mono','SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:32px;line-height:40px;font-weight:600;color:#09090b;-webkit-user-select:all;user-select:all;"><span style="letter-spacing:10px;">{{ $codeHead }}</span>{{ $codeTail }}</div>
            @isset($expires)
                <div style="margin-top:8px;font-size:13px;line-height:18px;color:#52525b;">Expires in {{ $expires }}</div>
            @endisset
        </td>
    </tr>
</table>
