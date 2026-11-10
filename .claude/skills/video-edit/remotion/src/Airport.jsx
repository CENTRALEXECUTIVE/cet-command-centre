import React from 'react';
import {
  AbsoluteFill, Sequence, OffthreadVideo, staticFile, useCurrentFrame, useVideoConfig,
  interpolate, spring, Easing, random,
} from 'remotion';

/* Editorial-premium style (Nov 2026): split-flap departures board, floating glass UI cards,
   two-tier type (small mono lead-in + big bold line with a serif-italic accent). All UI cards are
   illustrations: no real customer, flight or plate data. */

const GOLD = '#FBBA2A';
const css = `
@font-face{font-family:TT;src:url(${staticFile('TikTokSans-800.ttf')});font-weight:800}
@font-face{font-family:TT;src:url(${staticFile('TikTokSans-600.ttf')});font-weight:600}
@font-face{font-family:Mono;src:url(${staticFile('mono.ttf')})}
@font-face{font-family:SerifI;src:url(${staticFile('serif-italic.ttf')})}
`;
const ease = Easing.out(Easing.cubic);

const Footage = ({src, dur}) => {
  const f = useCurrentFrame();
  const s = interpolate(f, [0, dur], [1.04, 1.13]);
  const blur = interpolate(f, [0, 8], [18, 0], {extrapolateRight: 'clamp'});
  const fade = interpolate(f, [0, 6], [0, 1], {extrapolateRight: 'clamp'});
  return (
    <AbsoluteFill style={{opacity: fade}}>
      <AbsoluteFill style={{transform: `scale(${s})`, filter: `blur(${blur}px)`}}>
        <OffthreadVideo src={staticFile(src)} muted style={{width: '100%', height: '100%', objectFit: 'cover'}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: 'linear-gradient(180deg, rgba(5,8,16,0.55) 0%, rgba(5,8,16,0.05) 30%, rgba(5,8,16,0.15) 55%, rgba(5,8,16,0.85) 100%)'}} />
    </AbsoluteFill>
  );
};

/* ---- split-flap board ---- */
const CHARS = ' ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:→·';
const Flap = ({target, start, seed}) => {
  const f = useCurrentFrame();
  const settle = start + 6 + Math.floor(random(seed) * 14);
  let ch = target;
  if (f < settle) ch = f < start ? ' ' : CHARS[Math.floor(random(seed + f) * CHARS.length)];
  const flip = f < settle && f >= start ? (f % 2 ? 0.86 : 1) : 1;
  return (
    <div style={{width: 46, height: 66, margin: '0 2px', borderRadius: 6, background: 'linear-gradient(180deg,#1c1c1c 0 49%,#0b0b0b 51% 100%)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: 'inset 0 -1px 0 rgba(255,255,255,0.06), 0 4px 10px rgba(0,0,0,0.5)',
      position: 'relative', transform: `scaleY(${flip})`}}>
      <span style={{fontFamily: 'Mono', fontSize: 44, color: ch === ' ' ? 'transparent' : (ch === '→' || ch === '·' ? GOLD : '#f5d36b')}}>{ch === ' ' ? '_' : ch}</span>
      <div style={{position: 'absolute', left: 0, right: 0, top: '50%', height: 2, background: 'rgba(0,0,0,0.8)'}} />
    </div>
  );
};
const Board = ({rows}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const pop = spring({frame: f, fps, config: {damping: 18}});
  return (
    <div style={{position: 'absolute', top: 300, left: 50, right: 50, padding: '26px 22px 30px', borderRadius: 26,
      background: 'rgba(8,8,8,0.82)', border: '1.5px solid rgba(255,255,255,0.12)', boxShadow: '0 30px 80px rgba(0,0,0,0.6)',
      transform: `translateY(${(1 - pop) * 60}px)`, opacity: pop}}>
      <div style={{display: 'flex', justifyContent: 'space-between', fontFamily: 'Mono', fontSize: 24, letterSpacing: 6, color: 'rgba(255,255,255,0.55)', marginBottom: 16}}>
        <span>✈ DEPARTURES</span><span style={{color: GOLD}}>CET</span>
      </div>
      {rows.map((r, ri) => (
        <div key={ri} style={{display: 'flex', marginBottom: 10}}>
          {r.padEnd(19, ' ').slice(0, 19).split('').map((c, ci) => <Flap key={ci} target={c} start={4 + ri * 6} seed={ri * 100 + ci} />)}
        </div>
      ))}
    </div>
  );
};

/* ---- two-tier editorial type ---- */
const Title = ({lead, big, accent, y = 1240}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const a = spring({frame: f - 2, fps, config: {damping: 16}});
  const b = spring({frame: f - 6, fps, config: {damping: 15, stiffness: 150}});
  const c = spring({frame: f - 11, fps, config: {damping: 15, stiffness: 150}});
  const bar = interpolate(f, [4, 18], [0, 120], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease});
  return (
    <div style={{position: 'absolute', top: y, left: 80, right: 160}}>
      <div style={{fontFamily: 'Mono', fontSize: 28, letterSpacing: 5, color: GOLD, opacity: a, transform: `translateX(${(1 - a) * -30}px)`}}>{lead}</div>
      <div style={{width: bar, height: 3, background: GOLD, margin: '14px 0 18px', boxShadow: `0 0 10px ${GOLD}`}} />
      <div style={{fontFamily: 'TT', fontWeight: 800, fontSize: 92, lineHeight: 1.0, color: 'white', letterSpacing: -1,
        opacity: b, transform: `translateY(${(1 - b) * 40}px)`, filter: `blur(${(1 - b) * 8}px)`, textShadow: '0 4px 30px rgba(0,0,0,0.5)'}}>{big}</div>
      {accent ? <div style={{fontFamily: 'SerifI', fontSize: 104, lineHeight: 1.05, color: GOLD, opacity: c,
        transform: `translateY(${(1 - c) * 40}px)`, filter: `blur(${(1 - c) * 8}px)`, textShadow: '0 0 30px rgba(251,186,42,0.35)'}}>{accent}</div> : null}
    </div>
  );
};

/* ---- floating glass UI card (3D tilt in) ---- */
const Card = ({icon, title, lines, y = 560, delay = 4, tone = GOLD}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const s = spring({frame: f - delay, fps, config: {damping: 14, stiffness: 120}});
  const float = Math.sin((f + delay) / 14) * 8;
  return (
    <div style={{position: 'absolute', top: y + float, left: 120, right: 200, perspective: 1200}}>
      <div style={{transform: `rotateX(${(1 - s) * 45}deg) translateY(${(1 - s) * 120}px) scale(${0.85 + 0.15 * s})`, opacity: s,
        padding: '28px 30px', borderRadius: 30, background: 'rgba(255,255,255,0.14)', border: '1.5px solid rgba(255,255,255,0.28)',
        backdropFilter: 'blur(22px)', WebkitBackdropFilter: 'blur(22px)', boxShadow: '0 30px 80px rgba(0,0,0,0.45)',
        display: 'flex', gap: 24, alignItems: 'center'}}>
        <div style={{width: 92, height: 92, borderRadius: 24, background: tone, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 50, flex: 'none'}}>{icon}</div>
        <div style={{minWidth: 0}}>
          <div style={{fontFamily: 'TT', fontWeight: 800, fontSize: 42, color: 'white'}}>{title}</div>
          {lines.map((l, i) => <div key={i} style={{fontFamily: 'TT', fontWeight: 600, fontSize: 32, color: 'rgba(255,255,255,0.78)', marginTop: 6}}>{l}</div>)}
        </div>
      </div>
    </div>
  );
};

const Counter = ({n, total}) => {
  const f = useCurrentFrame();
  const o = interpolate(f, [0, 6], [0, 1], {extrapolateRight: 'clamp'});
  return (
    <div style={{position: 'absolute', top: 150, right: 160, fontFamily: 'Mono', fontSize: 30, letterSpacing: 4, color: 'white', opacity: o}}>
      <span style={{color: GOLD, fontSize: 64, fontFamily: 'TT', fontWeight: 800}}>{String(n).padStart(2, '0')}</span> / {String(total).padStart(2, '0')}
    </div>
  );
};

const End = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const a = spring({frame: f, fps, config: {damping: 18}});
  const b = spring({frame: f - 8, fps, config: {damping: 18}});
  const line = interpolate(f, [6, 24], [0, 300], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease});
  return (
    <AbsoluteFill style={{background: 'radial-gradient(circle at 50% 40%, #12161f 0%, #05070b 65%)', alignItems: 'center', justifyContent: 'center', textAlign: 'center'}}>
      <div style={{fontFamily: 'SerifI', fontSize: 84, color: 'white', opacity: a, transform: `translateY(${(1 - a) * 30}px)`}}>Every detail, <span style={{color: GOLD}}>handled.</span></div>
      <div style={{width: line, height: 3, background: GOLD, margin: '34px 0', boxShadow: `0 0 14px ${GOLD}`}} />
      <div style={{opacity: b, transform: `translateY(${(1 - b) * 20}px)`}}>
        <div style={{fontFamily: 'TT', fontWeight: 800, fontSize: 70, letterSpacing: 22, color: 'white', marginLeft: 22}}>CENTRAL</div>
        <div style={{fontFamily: 'Mono', fontSize: 26, letterSpacing: 5, color: 'rgba(255,255,255,0.6)', marginTop: 14}}>EXECUTIVE TRANSFERS · SHEFFIELD</div>
        <div style={{fontFamily: 'TT', fontWeight: 600, fontSize: 40, color: GOLD, marginTop: 26}}>centralexecutivetransfers.co.uk</div>
      </div>
    </AbsoluteFill>
  );
};

export const AIRPORT_STEPS = [
  {src: 'b1.mp4', lead: 'STEP 01 · FLIGHT', big: 'We track', accent: 'your flight.', card: {icon: '✈', title: 'Flight landed early', lines: ['Your chauffeur adjusts the pickup', 'You just walk out to the car']}},
  {src: 'b2.mp4', lead: 'STEP 02 · THE CAR', big: 'Cleaned', accent: 'inside and out.', card: null},
  {src: 'b3.mp4', lead: 'STEP 03 · ON BOARD', big: 'Fresh water', accent: 'ready for you.', card: null},
  {src: 'b4.mp4', lead: 'STEP 04 · YOUR DRIVER', big: 'Details sent', accent: 'before pickup.', card: {icon: '🚘', title: 'Your chauffeur is assigned', lines: ['Mercedes E-Class · Black', 'Live tracking link inside']}},
  {src: 'b5.mp4', lead: 'STEP 05 · ARRIVALS', big: 'Meet and greet', accent: 'in arrivals.', card: {icon: '🪧', title: 'Look for your name board', lines: ['Your chauffeur is in arrivals', 'Help with your bags to the car']}},
];

export const Airport = () => {
  const HOOK = 105, STEP = 75, END = 80;
  return (
    <AbsoluteFill style={{background: 'black'}}>
      <style>{css}</style>
      <Sequence durationInFrames={HOOK}>
        <Footage src="b0.mp4" dur={HOOK} />
        <Board rows={['SHEFFIELD → MAN', 'SHEFFIELD → LHR', 'SHEFFIELD → LBA']} />
        <Sequence from={28}><Title lead="CENTRAL EXECUTIVE TRANSFERS" big="5 things before" accent="every airport pickup." y={1150} /></Sequence>
      </Sequence>
      {AIRPORT_STEPS.map((s, i) => (
        <Sequence key={i} from={HOOK + i * STEP} durationInFrames={STEP}>
          <Footage src={s.src} dur={STEP} />
          <Counter n={i + 1} total={AIRPORT_STEPS.length} />
          {s.card ? <Card {...s.card} /> : null}
          <Title lead={s.lead} big={s.big} accent={s.accent} />
        </Sequence>
      ))}
      <Sequence from={HOOK + AIRPORT_STEPS.length * STEP} durationInFrames={END}><End /></Sequence>
    </AbsoluteFill>
  );
};
export const AIRPORT_DURATION = 105 + 5 * 75 + 80;
