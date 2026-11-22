<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New WixChat complaint</title>
</head>
<body style="margin:0; padding:24px; background:#fff8f5; font-family:Arial, Helvetica, sans-serif; color:#241512;">

    <div style="max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #f0e2dd; border-radius:16px; padding:28px;">

        <h2 style="margin:0 0 4px; color:#e85d3f;">New complaint received</h2>
        <p style="margin:0 0 22px; color:#9a8078; font-size:13px;">Sent from the WixChat website</p>

        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <tr>
                <td style="padding:8px 0; color:#9a8078; width:90px;">Name</td>
                <td style="padding:8px 0; font-weight:bold;">{{ $senderName }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0; color:#9a8078;">Email</td>
                <td style="padding:8px 0; font-weight:bold;">
                    <a href="mailto:{{ $senderEmail }}" style="color:#e85d3f;">{{ $senderEmail }}</a>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0; color:#9a8078;">Attachment</td>
                <td style="padding:8px 0;">{{ $file ? $file->getClientOriginalName() : 'None' }}</td>
            </tr>
            @if ($ip)
                <tr>
                    <td style="padding:8px 0; color:#9a8078;">IP address</td>
                    <td style="padding:8px 0;">{{ $ip }}</td>
                </tr>
            @endif
        </table>

        <hr style="border:none; border-top:1px solid #f0e2dd; margin:20px 0;">

        <p style="margin:0 0 8px; color:#9a8078; font-size:13px;">Complaint</p>
        <div style="background:#fff8f5; border-radius:12px; padding:16px; font-size:15px; line-height:1.6;">
            {!! nl2br(e($complaint)) !!}
        </div>

        <p style="margin:22px 0 0; color:#9a8078; font-size:12px;">
            Just hit Reply to answer {{ $senderName }} directly.
        </p>

    </div>

</body>
</html>