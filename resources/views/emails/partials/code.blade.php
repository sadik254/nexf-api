<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px;">
    <tr>
        <td align="center" style="background:#fafafa;border:1px solid #e4e4e7;border-radius:10px;padding:20px 12px;">
            <div class="code" style="padding-left:10px;font-family:'Geist Mono','SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:32px;line-height:40px;font-weight:600;letter-spacing:10px;color:#09090b;-webkit-user-select:all;user-select:all;">{{ $code }}</div>
            @isset($expires)
                <div style="margin-top:8px;font-size:13px;line-height:18px;color:#52525b;">Expires in {{ $expires }}</div>
            @endisset
        </td>
    </tr>
</table>
