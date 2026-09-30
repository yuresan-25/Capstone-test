<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Enrollment Summary — {{ $summary['sy'] }}</title>
  <style>
    @page { margin: 34px 36px 50px 36px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; }
    .head { border-bottom: 2px solid #1a2a5e; padding-bottom: 10px; margin-bottom: 14px; }
    .head td { vertical-align: middle; }
    .school { font-size: 13px; font-weight: bold; color: #1a2a5e; }
    .sub { font-size: 9px; color: #64748b; margin-top: 2px; }
    h1 { font-size: 15px; color: #1a2a5e; margin: 0 0 2px; }
    .meta { font-size: 9.5px; color: #64748b; margin-bottom: 14px; }
    table.stats { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 16px; }
    table.stats td { background: #f1f5f9; border-radius: 6px; padding: 8px 10px; text-align: center; }
    .stat-val { font-size: 16px; font-weight: bold; color: #1a2a5e; }
    .stat-label { font-size: 8.5px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #1a2a5e; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; padding: 7px 8px; text-align: left; }
    table.grid td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
    table.grid td.n, table.grid th.n { text-align: right; }
    table.grid tr.total td { font-weight: bold; background: #f8fafc; border-top: 2px solid #1a2a5e; }
    .note { margin-top: 14px; font-size: 8.5px; color: #94a3b8; }
  </style>
</head>
<body>
  <table class="head" width="100%">
    <tr>
      @if($logoData)<td width="54"><img src="{{ $logoData }}" width="44" height="44" alt=""></td>@endif
      <td>
        <div class="school">PREMIERE HEIGHTS LEARNING CENTER, INC.</div>
        <div class="sub">Enrollment System &bull; Official Enrollment Summary</div>
      </td>
    </tr>
  </table>

  <h1>Enrollment Summary — {{ $summary['sy'] }}</h1>
  <div class="meta">Enrollment period: {{ $summary['period'] }} &nbsp;&bull;&nbsp; Generated {{ $generatedAt }} by {{ $generatedBy }}</div>

  <table class="stats">
    <tr>
      <td><div class="stat-val">{{ $summary['totalApplications'] }}</div><div class="stat-label">Applications</div></td>
      <td><div class="stat-val">{{ $summary['totalPending'] }}</div><div class="stat-label">Pending review</div></td>
      <td><div class="stat-val">{{ $summary['totalApproved'] }}</div><div class="stat-label">Approved</div></td>
      <td><div class="stat-val">{{ $summary['totalEnrolled'] }}</div><div class="stat-label">Enrolled (sectioned)</div></td>
      <td><div class="stat-val">{{ $summary['totalSections'] }}</div><div class="stat-label">Sections</div></td>
    </tr>
  </table>

  <table class="grid">
    <thead>
      <tr><th>Grade Level</th><th class="n">Sections</th><th class="n">Applications</th><th class="n">Pending</th><th class="n">Approved</th><th class="n">Enrolled</th><th class="n">Approval Rate</th></tr>
    </thead>
    <tbody>
      @foreach($summary['grades'] as $g)
      <tr>
        <td>{{ $g['label'] }}</td>
        <td class="n">{{ $g['sections'] }}</td>
        <td class="n">{{ $g['applications'] }}</td>
        <td class="n">{{ $g['pending'] }}</td>
        <td class="n">{{ $g['approved'] }}</td>
        <td class="n">{{ $g['enrolled'] }}</td>
        <td class="n">{{ $g['applications'] ? round($g['approved'] / $g['applications'] * 100) . '%' : '—' }}</td>
      </tr>
      @endforeach
      <tr class="total">
        <td>Total</td>
        <td class="n">{{ $summary['totalSections'] }}</td>
        <td class="n">{{ $summary['totalApplications'] }}</td>
        <td class="n">{{ $summary['totalPending'] }}</td>
        <td class="n">{{ $summary['totalApproved'] }}</td>
        <td class="n">{{ $summary['totalEnrolled'] }}</td>
        <td class="n">{{ $summary['totalApplications'] ? round($summary['totalApproved'] / $summary['totalApplications'] * 100) . '%' : '—' }}</td>
      </tr>
    </tbody>
  </table>

  <div class="note">Approved includes students already sectioned. Enrolled = assigned to a section. Unfinished (draft) applications are not counted.</div>
</body>
</html>
