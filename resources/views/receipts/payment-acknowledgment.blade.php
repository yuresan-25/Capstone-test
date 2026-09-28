<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{{ $proof->receiptNumber() }}</title>
  <style>
    @page { margin: 32px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; }
    .head { border-bottom: 2px solid #1a2a5e; padding-bottom: 10px; }
    .head td { vertical-align: middle; }
    .school { font-size: 13px; font-weight: bold; color: #1a2a5e; }
    .school-sub { font-size: 9px; color: #64748b; margin-top: 2px; }
    .title { text-align: center; margin: 16px 0 4px; font-size: 14px; font-weight: bold; letter-spacing: 1.5px; color: #1a2a5e; }
    .meta { text-align: center; font-size: 9.5px; color: #64748b; margin-bottom: 14px; }
    table.info { width: 100%; border-collapse: collapse; }
    table.info td { padding: 6px 0; border-bottom: 1px solid #eef2f7; vertical-align: top; }
    table.info td.k { width: 38%; color: #64748b; }
    table.info td.v { font-weight: bold; }
    .amount-box { margin: 16px 0; padding: 12px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; }
    .amount-label { font-size: 9px; color: #166534; text-transform: uppercase; letter-spacing: 1px; }
    .amount { font-size: 20px; font-weight: bold; color: #166534; margin-top: 2px; }
    .stamp { display: inline-block; padding: 2px 8px; border: 1px solid #16a34a; color: #16a34a; font-size: 9px; font-weight: bold; border-radius: 4px; letter-spacing: 1px; }
    .note { margin-top: 18px; font-size: 8.5px; color: #94a3b8; line-height: 1.5; border-top: 1px solid #eef2f7; padding-top: 8px; }
  </style>
</head>
<body>
  <table class="head" width="100%">
    <tr>
      @if($logoData)
      <td width="54"><img src="{{ $logoData }}" width="46" height="46" alt=""></td>
      @endif
      <td>
        <div class="school">PREMIERE HEIGHTS LEARNING CENTER, INC.</div>
        <div class="school-sub">Tuition &amp; Payments &bull; Online Enrollment System</div>
      </td>
    </tr>
  </table>

  <div class="title">ACKNOWLEDGMENT RECEIPT</div>
  <div class="meta">No. {{ $proof->receiptNumber() }} &nbsp;&bull;&nbsp; Issued {{ $generatedAt }}</div>

  <table class="info">
    <tr><td class="k">Received from</td><td class="v">{{ $parentName ?: '—' }}</td></tr>
    <tr><td class="k">Student</td><td class="v">{{ $enrollment->first_name }} {{ $enrollment->last_name }}</td></tr>
    <tr><td class="k">Grade level</td><td class="v">{{ $enrollment->grade_level }}{{ $enrollment->lrn && $enrollment->lrn !== 'N/A' ? ' • LRN ' . $enrollment->lrn : '' }}</td></tr>
    <tr><td class="k">Payment for</td><td class="v">{{ $label }}{{ $schoolYear ? ' • SY ' . $schoolYear : '' }}</td></tr>
    <tr><td class="k">Mode of payment</td><td class="v">{{ $method }}{{ $proof->isOnline() ? ' (online via PayMongo)' : '' }}</td></tr>
    @if($proof->isOnline())
    <tr><td class="k">PayMongo reference</td><td class="v">{{ $proof->paymongo_payment_id }}</td></tr>
    @endif
    <tr><td class="k">Date paid</td><td class="v">{{ $proof->submitted_at?->format('M d, Y g:i A') }}</td></tr>
    <tr><td class="k">Verified</td><td class="v">{{ $proof->verified_at?->format('M d, Y g:i A') }} {{ $proof->isOnline() ? '— confirmed by PayMongo' : ($verifier ? '— by ' . $verifier : '') }}</td></tr>
  </table>

  <div class="amount-box">
    <div class="amount-label">Amount received</div>
    <div class="amount">&#8369;{{ number_format($proof->creditedAmount(), 2) }}</div>
    <div style="margin-top:6px"><span class="stamp">VERIFIED</span>
      <span style="color:#64748b;font-size:9px;margin-left:6px">{{ $label }} balance as of today: &#8369;{{ number_format($remaining, 2) }}</span>
    </div>
  </div>

  <div class="note">
    This is a system-generated acknowledgment that the payment above was received and verified.
    It is not a BIR official receipt; the school's official receipt is issued separately.
  </div>
</body>
</html>
