{{-- Rendered server-side by barryvdh/laravel-dompdf (see
     EnrollmentController::export()). Dompdf only supports a subset of CSS —
     no flexbox/grid — so this uses tables and inline styles on purpose. --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{{ $title }}</title>
  <style>
    @page { margin: 130px 40px 70px 40px; }

    body {
      font-family: DejaVu Sans, sans-serif;
      font-size: 9.5px;
      color: #1e293b;
      margin: 0;
    }

    header {
      position: fixed;
      top: -100px;
      left: 0;
      right: 0;
      height: 90px;
    }

    footer {
      position: fixed;
      bottom: -45px;
      left: 0;
      right: 0;
      height: 35px;
      font-size: 8.5px;
      color: #94a3b8;
    }

    .school-name {
      font-size: 15px;
      font-weight: bold;
      color: #1a2a5e;
      margin: 0 0 2px;
    }

    .school-sub {
      font-size: 9px;
      color: #64748b;
      margin: 0;
    }

    .doc-title {
      font-size: 12px;
      font-weight: bold;
      margin: 10px 0 2px;
      color: #1e293b;
    }

    .meta {
      font-size: 8.5px;
      color: #64748b;
    }

    hr.rule {
      border: none;
      border-top: 2px solid #1a2a5e;
      margin: 8px 0 0;
    }

    table.data {
      width: 100%;
      border-collapse: collapse;
      margin-top: 4px;
    }

    table.data thead th {
      background: #1a2a5e;
      color: #fff;
      font-size: 8.5px;
      text-transform: uppercase;
      letter-spacing: .03em;
      text-align: left;
      padding: 6px 6px;
      border: 1px solid #1a2a5e;
    }

    table.data tbody td {
      padding: 5px 6px;
      border: 1px solid #e2e8f0;
      vertical-align: top;
    }

    table.data tbody tr:nth-child(even) td {
      background: #f8fafc;
    }

    .num { text-align: center; width: 26px; color: #94a3b8; }
    .muted { color: #94a3b8; }

    .badge {
      font-size: 8px;
      font-weight: bold;
      padding: 2px 6px;
      border-radius: 8px;
    }
    .badge-enrolled { background: #dcfce7; color: #166534; }
    .badge-approved { background: #e0f2fe; color: #0369a1; }

    .empty {
      text-align: center;
      padding: 30px;
      color: #94a3b8;
      font-style: italic;
    }

    .total-line {
      margin-top: 10px;
      font-size: 9px;
      font-weight: bold;
      color: #1e293b;
    }
  </style>
</head>
<body>

  <header>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        @if($logoData)
        <td style="width:52px;vertical-align:middle">
          <img src="{{ $logoData }}" style="width:46px;height:46px">
        </td>
        @endif
        <td style="vertical-align:middle">
          <p class="school-name">PREMIERE HEIGHTS LEARNING CENTER, INC.</p>
          <p class="school-sub">Official Student Records &bull; Enrollment System</p>
        </td>
        <td style="vertical-align:middle;text-align:right">
          <div class="doc-title">{{ $title }}</div>
          <div class="meta">Generated {{ $generatedAt }}</div>
          <div class="meta">By: {{ $generatedBy }}</div>
        </td>
      </tr>
    </table>
    <hr class="rule">
  </header>

  <footer>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <td style="font-size:8.5px;color:#94a3b8">
          PHLCI Enrollment System &bull; Confidential student record
        </td>
        <td></td>
      </tr>
    </table>
  </footer>

  <main>
    <div class="meta" style="margin-bottom:6px">
      <strong>Filter:</strong> {{ $gradeFilter }}
      &nbsp;&bull;&nbsp;
      <strong>Records:</strong> {{ $students->count() }}
    </div>

    @if($students->isEmpty())
      <div class="empty">No students match this filter.</div>
    @else
    <table class="data">
      <thead>
        <tr>
          <th class="num">#</th>
          <th style="width:20%">Name</th>
          <th>LRN</th>
          <th>Grade</th>
          <th>Section</th>
          <th>Session</th>
          <th>Birthday</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        @foreach($students as $i => $s)
        <tr>
          <td class="num">{{ $i + 1 }}</td>
          <td>
            <strong>{{ $s->last_name }}, {{ $s->first_name }}</strong>
            @if($s->middle_name && $s->middle_name !== 'N/A')
              {{ $s->middle_name }}
            @endif
          </td>
          <td>{{ $s->lrn && $s->lrn !== 'N/A' ? $s->lrn : '—' }}</td>
          <td>{{ $s->grade_level }}</td>
          <td>{{ $s->section->name ?? '—' }}</td>
          <td>{{ $s->preferred_session ?? '—' }}</td>
          <td>{{ $s->birthday ? \Carbon\Carbon::parse($s->birthday)->format('M d, Y') : '—' }}</td>
          <td>
            <span class="badge {{ $s->status === 'enrolled' ? 'badge-enrolled' : 'badge-approved' }}">
              {{ ucfirst($s->status) }}
            </span>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <div class="total-line">Total: {{ $students->count() }} student(s)</div>
    @endif
  </main>

  {{-- Page numbers ("1 of 3") are stamped onto every page by
       EnrollmentController::exportPdf() via the dompdf canvas — dompdf's
       inline <script type="text/php"> is disabled by default. --}}

</body>
</html>