<?php if (!isset($sefReportForm)) { http_response_code(404); exit; } ?>
<div class="sef-report-letterhead sef-word-stationery">
    <img class="sef-word-paper" src="../assets/img/print/evaluation-form-letterhead.jpg" alt="">
    <img class="sef-word-bagong-pilipinas" src="../assets/img/print/faculty-performance-bagong-pilipinas.png" alt="Bagong Pilipinas">
    <img class="sef-word-university-seal" src="../assets/img/print/faculty-performance-university-seal.png" alt="">
    <div class="sef-word-university">
        <div>Republic of the Philippines</div>
        <strong>SULTAN KUDARAT STATE UNIVERSITY</strong>
        <address>EJC Montilla, City of Tacurong, 9800<br>Province of Sultan Kudarat</address>
    </div>
    <div class="sef-word-form-number">
        SKSU-INS-EFP-<?= sef_h($sefReportForm['number']) ?><br>
        Revision: 00<br>
        Effective Date: July 07, 2025
    </div>
    <div class="sef-word-university-footer">
        <strong>VISION:</strong> A leading University in advancing scholarly innovation, multi-cultural convergence, and responsive public service in a borderless Region. | <strong>MISSION:</strong> The University shall<br>
        primarily provide advanced instruction and professional training in science and technology, agriculture, fisheries, education and other relevant fields of study. It shall also<br>
        undertake research and extension services, and provide progressive leadership in its areas of specialization. | <strong>MAXIM:</strong> Generator of Solutions.<br>
        | <strong>CORE VALUES:</strong> Patriotism, Respect, Integrity, Zeal, Excellence in Public Service.
    </div>
</div>
