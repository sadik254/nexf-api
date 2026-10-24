<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px;">
    <tr>
        <td align="center" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:20px 12px;">
            <div class="code" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:34px;line-height:40px;font-weight:700;letter-spacing:10px;color:#1e3a8a;-webkit-user-select:all;user-select:all;">{{ $code }}</div>
            @isset($expires)
                <div style="margin-top:8px;font-size:13px;line-height:18px;color:#4b5563;">Expires in {{ $expires }}</div>
            @endisset
        </td>
    </tr>
</table>
