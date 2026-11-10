import React from 'react';
import {Composition} from 'remotion';
import {Reel, reelDuration} from './Reel';
import prep from './prep.json';
import {Airport, AIRPORT_DURATION} from './Airport';

export const Root = () => (
  <>
    <Composition id="PrepReel" component={Reel} width={1080} height={1920} fps={30}
      durationInFrames={reelDuration(prep.segments)} defaultProps={prep}
      calculateMetadata={({props}) => ({durationInFrames: reelDuration(props.segments)})} />
    <Composition id="Airport" component={Airport} width={1080} height={1920} fps={30} durationInFrames={AIRPORT_DURATION} />
  </>
);
