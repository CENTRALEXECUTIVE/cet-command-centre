import React from 'react';
import {
  AbsoluteFill, Sequence, OffthreadVideo, Audio, staticFile, useCurrentFrame,
  useVideoConfig, interpolate, spring, Easing,
} from 'remotion';

const GOLD = '#FBBA2A';
const fontCss = `
@font-face{font-family:TT;src:url(${staticFile('TikTokSans-800.ttf')});font-weight:800}
@font-face{font-family:TT;src:url(${staticFile('TikTokSans-600.ttf')});font-weight:600}
@font-face{font-family:Mono;src:url(${staticFile('mono.ttf')})}
`;

/* ---------- video segment with zoom-blur entry + flash ---------- */
const Clip = ({src, dur}) => {
  const f = useCurrentFrame();
  const enter = interpolate(f, [0, 8], [1.28, 1.06], {extrapolateRight: 'clamp', easing: Easing.out(Easing.cubic)});
  const drift = interpolate(f, [8, dur], [1.06, 1.12], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const scale = f < 8 ? enter : drift;
  const blur = interpolate(f, [0, 7], [14, 0], {extrapolateRight: 'clamp'});
  const flash = interpolate(f, [0, 5], [0.55, 0], {extrapolateRight: 'clamp'});
  return (
    <AbsoluteFill>
      <AbsoluteFill style={{transform: `scale(${scale})`, filter: `blur(${blur}px)`}}>
        <OffthreadVideo src={staticFile(src)} muted style={{width: '100%', height: '100%', objectFit: 'cover'}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: 'white', opacity: flash, mixBlendMode: 'screen'}} />
    </AbsoluteFill>
  );
};

/* ---------- futuristic HUD (persistent) ---------- */
const HUD = ({total, step, steps}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const scanY = (f * 9) % 2100 - 100;
  const t = f / fps;
  const tc = `00:${String(Math.floor(t)).padStart(2, '0')}:${String(Math.floor((t % 1) * 30)).padStart(2, '0')}`;
  const pulse = 0.4 + 0.6 * Math.abs(Math.sin(f / 8));
  const br = (s) => ({position: 'absolute', width: 70, height: 70, borderColor: GOLD, borderStyle: 'solid', borderWidth: 0, ...s});
  const fadeIn = interpolate(f, [0, 12], [0, 1], {extrapolateRight: 'clamp'});
  return (
    <AbsoluteFill style={{opacity: fadeIn, pointerEvents: 'none'}}>
      {/* scan line */}
      <div style={{position: 'absolute', left: 0, right: 0, top: scanY, height: 140,
        background: 'linear-gradient(180deg, rgba(251,186,42,0) 0%, rgba(251,186,42,0.10) 50%, rgba(251,186,42,0) 100%)'}} />
      {/* fine horizontal lines */}
      <AbsoluteFill style={{background: 'repeating-linear-gradient(0deg, rgba(255,255,255,0.025) 0 2px, transparent 2px 6px)'}} />
      {/* corner brackets */}
      <div style={br({top: 120, left: 60, borderTopWidth: 4, borderLeftWidth: 4})} />
      <div style={br({top: 120, right: 60, borderTopWidth: 4, borderRightWidth: 4})} />
      <div style={br({bottom: 330, left: 60, borderBottomWidth: 4, borderLeftWidth: 4})} />
      <div style={br({bottom: 330, right: 60, borderBottomWidth: 4, borderRightWidth: 4})} />
      {/* labels */}
      <div style={{position: 'absolute', top: 140, left: 150, fontFamily: 'Mono', fontSize: 26, color: 'rgba(255,255,255,0.85)', letterSpacing: 3}}>
        <span style={{color: '#ff3b3b', opacity: pulse}}>●</span> CET // SHEFFIELD
      </div>
      <div style={{position: 'absolute', top: 140, right: 150, fontFamily: 'Mono', fontSize: 26, color: 'rgba(255,255,255,0.85)', letterSpacing: 2}}>{tc}</div>
      <div style={{position: 'absolute', bottom: 345, left: 150, fontFamily: 'Mono', fontSize: 22, color: 'rgba(255,255,255,0.7)', letterSpacing: 3}}>53.38°N  1.47°W</div>
      {steps ? (
        <div style={{position: 'absolute', bottom: 345, right: 150, fontFamily: 'Mono', fontSize: 22, color: GOLD, letterSpacing: 3}}>
          {String(Math.max(1, step)).padStart(2, '0')} / {String(steps).padStart(2, '0')}
        </div>) : null}
      {/* progress bar */}
      <div style={{position: 'absolute', bottom: 320, left: 60, right: 60, height: 3, background: 'rgba(255,255,255,0.15)'}}>
        <div style={{height: '100%', width: `${Math.min(100, (f / total) * 100)}%`, background: GOLD, boxShadow: `0 0 12px ${GOLD}`}} />
      </div>
    </AbsoluteFill>
  );
};

/* ---------- kinetic caption: words spring up, gold word glows, underline wipes ---------- */
const Caption = ({text, emphasis = [], y = 1130, size = 72, dur}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const words = text.split(' ');
  const emph = emphasis.map((e) => e.toLowerCase());
  const out = interpolate(f, [dur - 6, dur], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const panelIn = spring({frame: f, fps, config: {damping: 18, stiffness: 160}});
  const line = interpolate(f, [8, 20], [0, 100], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.out(Easing.cubic)});
  return (
    <div style={{position: 'absolute', top: y, left: 60, right: 140, display: 'flex', justifyContent: 'center', opacity: out}}>
      <div style={{position: 'relative', padding: '22px 34px 26px', borderRadius: 26,
        background: 'rgba(10,10,10,0.42)', border: '1.5px solid rgba(255,255,255,0.18)',
        backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)',
        boxShadow: '0 20px 60px rgba(0,0,0,0.35)', transform: `scale(${0.9 + 0.1 * panelIn})`, opacity: panelIn,
        display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: '0 18px', maxWidth: 900}}>
        {words.map((w, i) => {
          const s = spring({frame: f - 3 - i * 3, fps, config: {damping: 14, stiffness: 180}});
          const isE = emph.includes(w.replace(/[.,!?]/g, '').toLowerCase());
          return (
            <span key={i} style={{fontFamily: 'TT', fontWeight: 800, fontSize: size, lineHeight: 1.12,
              color: isE ? GOLD : 'white', display: 'inline-block',
              transform: `translateY(${(1 - s) * 50}px)`, opacity: s, filter: `blur(${(1 - s) * 8}px)`,
              textShadow: isE ? `0 0 24px rgba(251,186,42,0.75)` : '0 2px 12px rgba(0,0,0,0.45)'}}>{w}</span>
          );
        })}
        <div style={{position: 'absolute', left: 34, bottom: 12, height: 4, width: `calc(${line}% - 68px)`, maxWidth: 'calc(100% - 68px)',
          background: GOLD, borderRadius: 2, boxShadow: `0 0 14px ${GOLD}`}} />
      </div>
    </div>
  );
};

/* ---------- hook: glitch + typewriter on white pills ---------- */
const Hook = ({text, dur}) => {
  const f = useCurrentFrame();
  const chars = Math.floor(interpolate(f, [2, 30], [0, text.length], {extrapolateRight: 'clamp'}));
  const shown = text.slice(0, chars);
  const glitch = f < 10 ? (10 - f) * 1.6 : (f % 23 === 0 ? 6 : 0);
  const out = interpolate(f, [dur - 6, dur], [1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  // Balanced 2-line split of the FULL hook, then reveal characters across it.
  const words = text.split(' ');
  let best = 1, bestW = 1e9;
  for (let i = 1; i < words.length; i++) {
    const a = words.slice(0, i).join(' ').length, b = words.slice(i).join(' ').length;
    if (Math.max(a, b) < bestW) { bestW = Math.max(a, b); best = i; }
  }
  const full = text.length <= 20 ? [text] : [words.slice(0, best).join(' '), words.slice(best).join(' ')];
  let left = chars;
  const lines = full.map((l) => { const s = l.slice(0, Math.max(0, left)); left -= l.length + 1; return s; }).filter((l) => l.length);
  return (
    <div style={{position: 'absolute', top: 360, left: 40, right: 120, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10, opacity: out}}>
      {lines.map((l, i) => (
        <div key={i} style={{background: 'white', borderRadius: 18, padding: '10px 26px', position: 'relative', whiteSpace: 'nowrap'}}>
          <span style={{fontFamily: 'TT', fontWeight: 800, fontSize: 64, color: '#0c0c0c',
            textShadow: glitch ? `${glitch}px 0 rgba(255,0,60,0.7), ${-glitch}px 0 rgba(0,220,255,0.7)` : 'none'}}>{l}</span>
          {i === lines.length - 1 && chars < text.length ? <span style={{fontFamily: 'TT', fontWeight: 800, fontSize: 70, color: GOLD}}>|</span> : null}
        </div>
      ))}
    </div>
  );
};

/* ---------- end card: light sweep + gold line draw ---------- */
const EndCard = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const letters = 'CENTRAL'.split('');
  const lineW = interpolate(f, [10, 28], [0, 340], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.out(Easing.cubic)});
  const sweep = interpolate(f, [14, 40], [-400, 1400], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const sub = spring({frame: f - 22, fps, config: {damping: 20}});
  return (
    <AbsoluteFill style={{background: 'radial-gradient(circle at 50% 45%, #1b1710 0%, #070707 60%)', alignItems: 'center', justifyContent: 'center'}}>
      <div style={{position: 'relative', display: 'flex', gap: 34, overflow: 'hidden', padding: '10px 20px'}}>
        {letters.map((c, i) => {
          const s = spring({frame: f - i * 2, fps, config: {damping: 16, stiffness: 140}});
          return <span key={i} style={{fontFamily: 'TT', fontWeight: 800, fontSize: 112, color: 'white',
            transform: `translateY(${(1 - s) * 80}px)`, opacity: s}}>{c}</span>;
        })}
        <div style={{position: 'absolute', top: 0, bottom: 0, left: sweep, width: 160,
          background: 'linear-gradient(90deg, transparent, rgba(255,255,255,0.55), transparent)', transform: 'skewX(-20deg)'}} />
      </div>
      <div style={{height: 5, width: lineW, background: GOLD, boxShadow: `0 0 18px ${GOLD}`, margin: '18px 0 30px'}} />
      <div style={{opacity: sub, transform: `translateY(${(1 - sub) * 20}px)`, textAlign: 'center'}}>
        <div style={{fontFamily: 'TT', fontWeight: 600, fontSize: 46, color: 'white'}}>Executive Transfers</div>
        <div style={{fontFamily: 'Mono', fontSize: 26, color: 'rgba(255,255,255,0.6)', letterSpacing: 4, marginTop: 14}}>SHEFFIELD · SOUTH YORKSHIRE · NATIONWIDE</div>
        <div style={{fontFamily: 'TT', fontWeight: 600, fontSize: 40, color: GOLD, marginTop: 22}}>centralexecutivetransfers.co.uk</div>
      </div>
    </AbsoluteFill>
  );
};

export const Reel = ({hook, segments, steps}) => {
  const END = 75;
  let at = 0;
  const placed = segments.map((s) => { const p = {...s, from: at}; at += s.dur; return p; });
  const total = at + END;
  return (
    <AbsoluteFill style={{background: 'black'}}>
      <style>{fontCss}</style>
      {placed.map((s, i) => (
        <Sequence key={i} from={s.from} durationInFrames={s.dur}>
          <Clip src={s.src} dur={s.dur} />
          {s.caption ? <Sequence from={i === 0 && hook ? 40 : 0}><Caption text={s.caption} emphasis={s.emphasis} dur={s.dur - (i === 0 && hook ? 40 : 0)} /></Sequence> : null}
        </Sequence>
      ))}
      {hook ? <Sequence from={0} durationInFrames={placed[0].dur}><Hook text={hook} dur={placed[0].dur} /></Sequence> : null}
      <Sequence from={0} durationInFrames={at}>
        <HUD total={at} steps={steps} />
      </Sequence>
      <Sequence from={at} durationInFrames={END}>
        <EndCard />
      </Sequence>
    </AbsoluteFill>
  );
};

export const reelDuration = (segments) => segments.reduce((a, s) => a + s.dur, 0) + 75;
