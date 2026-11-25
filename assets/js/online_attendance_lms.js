(() => {
  'use strict';
  const zone = 'Asia/Manila';
  const day = new Intl.DateTimeFormat('en-PH', {timeZone: zone, month:'short', day:'numeric', year:'numeric'});
  const time = new Intl.DateTimeFormat('en-PH', {timeZone: zone, hour:'numeric', minute:'2-digit', hour12:true});
  const field = new Intl.DateTimeFormat('en-PH', {
    timeZone: zone, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', hourCycle:'h23'
  });
  const escape = value => String(value ?? '').replace(/[&<>"']/g, character =>
    ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'})[character]);
  const labels = {scheduled:'Scheduled', open:'Open', closed:'Closed', pending:'Pending', present:'Present',
    absent:'Absent', cancelled:'Cancelled', not_eligible:'Not on session roster'};
  const periods = {midterm:'Midterm', final:'Final'};

  function dateTime(value) {
    const date = new Date(value);
    return `${day.format(date)}, ${time.format(date)} PHT`;
  }
  function windowText(opensAt, closesAt) {
    const opens = new Date(opensAt);
    const closes = new Date(closesAt);
    const lastDay = day.format(closes);
    const firstDay = day.format(opens);
    return `${firstDay}, ${time.format(opens)} – ${lastDay === firstDay ? '' : lastDay + ', '}${time.format(closes)} PHT`;
  }
  function dateField(value) {
    const parts = Object.fromEntries(field.formatToParts(new Date(value)).map(part => [part.type,part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
  }
  async function request(config, action, fields = {}) {
    let response;
    try {
      response = await fetch(config.endpoint, {
        method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{'Content-Type':'application/json', Accept:'application/json'},
        body:JSON.stringify({action, csrf_token:config.csrf_token, ...fields})
      });
    } catch {
      throw new Error('The connection was interrupted. Please try again.');
    }
    let data;
    try { data = await response.json(); }
    catch { throw new Error('Attendance returned an invalid response. Please try again.'); }
    if (!response.ok || !data.ok) {
      const error = new Error(data.message || 'Attendance is temporarily unavailable.');
      error.status = response.status;
      throw error;
    }
    return data;
  }
  window.synkOnlineAttendanceLms = {escape, labels, periods, dateTime, windowText, dateField, request};
})();
