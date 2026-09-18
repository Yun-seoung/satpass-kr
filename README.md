# SatPass KR

위성 관측 정보와 우주기상을 한 화면에서 확인하는 웹 서비스

## 주요 기능

- ISS 등 위성 통과 시간 예측 (SGP4 알고리즘)
- NOAA 우주기상 Kp 지수 연동
- ISS 실시간 위치 지도
- 달 정보 (월령, 월출/월몰)
- 일출/일몰 및 최적 관측 시간
- 이번 주 베스트 패스 TOP 3

## 기술 스택

- Frontend: HTML/CSS/JavaScript, jQuery
- Backend: PHP 8.x, Apache
- 데이터 저장: JSON 파일
- 외부 데이터: CelesTrak GP JSON, NOAA SWPC JSON
- 라이브러리: satellite.js, SunCalc, Leaflet.js

## 실행 방법

1. Apache + PHP 8.x 환경 필요
2. 프로젝트를 `htdocs/satpass/` 에 복사
3. `http://localhost/satpass/` 접속

## 데이터 출처

- 궤도요소: [CelesTrak](https://celestrak.org)
- 우주기상: [NOAA SWPC](https://www.swpc.noaa.gov)
