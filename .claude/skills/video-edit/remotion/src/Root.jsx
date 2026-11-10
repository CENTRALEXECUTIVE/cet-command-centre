import React from 'react';
import {Composition} from 'remotion';
import {Reel, reelDuration} from './Reel';
import prep from './prep.json';

export const Root = () => (
  <>
    <Composition id="PrepReel" component={Reel} width={1080} height={1920} fps={30}
      durationInFrames={reelDuration(prep.segments)} defaultProps={prep}
      calculateMetadata={({props}) => ({durationInFrames: reelDuration(props.segments)})} />
  </>
);
