/**
 * passcalc.js — 위성 패스 예측 + 우주기상 주의도 계산
 *
 * 의존: satellite.js 6.0.2 (전역 변수 `satellite`)
 *   <script src="https://cdn.jsdelivr.net/npm/satellite.js@6.0.2/dist/satellite.min.js"></script>
 *
 * 전역으로 `PassCalc`를 노출한다. 브라우저와 Node(테스트) 양쪽에서 동작.
 */
(function (root) {
  'use strict';

  /** SWPC time_tag("2026-09-15 03:00:00" 또는 "2026-09-15T03:00:00")를 UTC Date로 */
  function parseUtc(tag) {
    if (typeof tag !== 'string') return null;
    let s = tag.trim().replace(' ', 'T');
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) s += 'Z';
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  }

  /** 궤도요소(OMM JSON)의 나이(시간). CelesTrak EPOCH는 시간대 표기가 없는 UTC */
  function epochAgeHours(omm, now) {
    const epoch = parseUtc(omm.EPOCH);
    if (!epoch) return null;
    return (now.getTime() - epoch.getTime()) / 3600000;
  }

  /** 특정 시각의 앙각·방위각(도) */
  function lookAngles(sat, satrec, observer, date) {
    const pv = sat.propagate(satrec, date);
    if (!pv || !pv.position) return null; // 궤도요소가 너무 오래됐거나 재진입 등으로 계산 실패
    const gmst = sat.gstime(date);
    const ecf = sat.eciToEcf(pv.position, gmst);
    const la = sat.ecfToLookAngles(observer, ecf);
    return {
      el: sat.radiansToDegrees(la.elevation),
      az: (sat.radiansToDegrees(la.azimuth) + 360) % 360,
    };
  }

  /**
   * 패스 예측
   * @param {object} opts
   *   sat: satellite.js 모듈, omm: CelesTrak JSON 1건,
   *   lat/lon(도), altKm(km), minEl(도), start(Date), hours, stepSec
   * @returns {Array<{aos,tca,los,aosAz,losAz,maxEl,durationMin}>}
   */
  function predictPasses(opts) {
    const sat = opts.sat;
    const satrec = sat.json2satrec(opts.omm);
    const observer = {
      latitude: sat.degreesToRadians(opts.lat),
      longitude: sat.degreesToRadians(opts.lon),
      height: opts.altKm || 0,
    };
    const minEl = opts.minEl ?? 10;
    const step = (opts.stepSec || 20) * 1000;
    const startMs = opts.start.getTime();
    const endMs = startMs + (opts.hours || 24) * 3600000;

    const passes = [];
    let cur = null;
    for (let t = startMs; t <= endMs; t += step) {
      const date = new Date(t);
      const la = lookAngles(sat, satrec, observer, date);
      if (!la) continue;
      if (la.el >= minEl) {
        if (!cur) cur = { aos: date, aosAz: la.az, maxEl: la.el, tca: date };
        if (la.el > cur.maxEl) { cur.maxEl = la.el; cur.tca = date; }
        cur.los = date; cur.losAz = la.az;
      } else if (cur) {
        cur.durationMin = (cur.los - cur.aos) / 60000;
        passes.push(cur);
        cur = null;
      }
    }
    // 검색 구간 끝에서 진행 중인 패스는 버린다(LOS를 모르므로)
    return passes;
  }

  /** Kp → NOAA G 등급 문자열 (Kp 5=G1 … 9=G5) */
  function kpToG(kp) {
    if (kp >= 9) return 'G5';
    if (kp >= 8) return 'G4';
    if (kp >= 7) return 'G3';
    if (kp >= 6) return 'G2';
    if (kp >= 5) return 'G1';
    return 'G0';
  }

  /**
   * 패스 시각에 해당하는 Kp 찾기: 예보는 3시간 구간(time_tag = 구간 시작)
   * @param {Array<{time_tag, kp}>} forecast  api.php?src=kp_forecast 의 data
   */
  function kpAt(forecast, date) {
    let best = null;
    for (const row of forecast || []) {
      const t = parseUtc(row.time_tag);
      if (!t) continue;
      if (t.getTime() <= date.getTime() && date.getTime() < t.getTime() + 3 * 3600000) {
        best = row;
      }
    }
    return best;
  }

  /**
   * 운용 주의도(과제용 단순 규칙)
   *  - Kp ≥ 7(G3↑): 경계 / Kp ≥ 5(G1↑): 주의 / 그 외: 정상
   *  - 궤도요소가 72시간 이상 지났으면 정확도 경고를 덧붙임
   */
  function assessPass(pass, forecast, ageHours) {
    const row = kpAt(forecast, pass.aos);
    const kp = row ? Number(row.kp) : null;
    let level = '정상';
    if (kp !== null && kp >= 7) level = '경계';
    else if (kp !== null && kp >= 5) level = '주의';
    const notes = [];
    if (kp === null) notes.push('해당 시각 Kp 예보 없음');
    else notes.push(`Kp ${kp.toFixed(2)} (${kpToG(kp)})`);
    if (ageHours !== null && ageHours >= 72) notes.push('궤도요소 3일 이상 경과: 시각 오차 가능');
    return { level, kp, notes };
  }

  const api = { parseUtc, epochAgeHours, predictPasses, kpToG, kpAt, assessPass };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.PassCalc = api;
})(typeof window !== 'undefined' ? window : globalThis);
