const finite = (value, label) => {
  const number = Number(value);
  if (!Number.isFinite(number)) throw new TypeError(`${label} must be finite`);
  return number;
};

export function clampPoint(point, width, height) {
  width = finite(width, 'width'); height = finite(height, 'height');
  if (width <= 0 || height <= 0) throw new RangeError('surface must be positive');
  return {
    x: Math.min(width, Math.max(0, finite(point?.x, 'x'))),
    y: Math.min(height, Math.max(0, finite(point?.y, 'y'))),
  };
}

export function normalizeBox(start, end, width, height) {
  const a = clampPoint(start, width, height);
  const b = clampPoint(end, width, height);
  const pixelWidth = Math.abs(b.x - a.x);
  const pixelHeight = Math.abs(b.y - a.y);
  if (pixelWidth < 2 || pixelHeight < 2) throw new RangeError('annotation too small');
  return {
    x: Math.min(a.x, b.x) / width,
    y: Math.min(a.y, b.y) / height,
    width: pixelWidth / width,
    height: pixelHeight / height,
  };
}

export function normalizeArrow(start, end, width, height) {
  const a = clampPoint(start, width, height);
  const b = clampPoint(end, width, height);
  if (Math.hypot(b.x - a.x, b.y - a.y) < 2) throw new RangeError('annotation too small');
  return { x1: a.x / width, y1: a.y / height, x2: b.x / width, y2: b.y / height };
}

export function geometryToPixels(kind, geometry, width, height) {
  width = finite(width, 'width'); height = finite(height, 'height');
  if (kind === 'arrow') {
    return {
      x1: finite(geometry.x1, 'x1') * width,
      y1: finite(geometry.y1, 'y1') * height,
      x2: finite(geometry.x2, 'x2') * width,
      y2: finite(geometry.y2, 'y2') * height,
    };
  }
  return {
    left: finite(geometry.x, 'x') * width,
    top: finite(geometry.y, 'y') * height,
    width: finite(geometry.width, 'geometry width') * width,
    height: finite(geometry.height, 'geometry height') * height,
  };
}

export function geometryToViewportPixels(kind, geometry, saved, current) {
  const savedWidth=finite(saved.width,'saved width'),savedHeight=finite(saved.height,'saved height');
  const savedX=finite(saved.scroll_x??0,'saved scroll x'),savedY=finite(saved.scroll_y??0,'saved scroll y');
  const currentX=finite(current.scroll_x??0,'current scroll x'),currentY=finite(current.scroll_y??0,'current scroll y');
  if(savedWidth<=0||savedHeight<=0)throw new RangeError('saved viewport must be positive');
  if(kind==='arrow')return {x1:finite(geometry.x1,'x1')*savedWidth+savedX-currentX,y1:finite(geometry.y1,'y1')*savedHeight+savedY-currentY,x2:finite(geometry.x2,'x2')*savedWidth+savedX-currentX,y2:finite(geometry.y2,'y2')*savedHeight+savedY-currentY};
  return {left:finite(geometry.x,'x')*savedWidth+savedX-currentX,top:finite(geometry.y,'y')*savedHeight+savedY-currentY,width:finite(geometry.width,'geometry width')*savedWidth,height:finite(geometry.height,'geometry height')*savedHeight};
}

export function distanceToSegment(point, start, end) {
  const px=finite(point.x,'point x'),py=finite(point.y,'point y'),x1=finite(start.x,'start x'),y1=finite(start.y,'start y'),x2=finite(end.x,'end x'),y2=finite(end.y,'end y');
  const dx=x2-x1,dy=y2-y1,lengthSquared=dx*dx+dy*dy;
  if(lengthSquared===0)return Math.hypot(px-x1,py-y1);
  const t=Math.max(0,Math.min(1,((px-x1)*dx+(py-y1)*dy)/lengthSquared));
  return Math.hypot(px-(x1+t*dx),py-(y1+t*dy));
}

export function markerBoxAt(point, width, height, diameter = 40) {
  const center=clampPoint(point,width,height);
  const size=Math.max(24,Math.min(64,finite(diameter,'diameter'),width,height));
  const left=Math.min(width-size,Math.max(0,center.x-size/2));
  const top=Math.min(height-size,Math.max(0,center.y-size/2));
  return {x:left/width,y:top/height,width:size/width,height:size/height};
}

export function translateViewportPixels(kind, pixels, delta, width, height) {
  width=finite(width,'width');height=finite(height,'height');
  const dx=finite(delta?.x,'delta x'),dy=finite(delta?.y,'delta y');
  if(kind==='arrow'){
    const x1=finite(pixels.x1,'x1'),y1=finite(pixels.y1,'y1'),x2=finite(pixels.x2,'x2'),y2=finite(pixels.y2,'y2');
    const appliedX=Math.min(width-Math.max(x1,x2),Math.max(-Math.min(x1,x2),dx));
    const appliedY=Math.min(height-Math.max(y1,y2),Math.max(-Math.min(y1,y2),dy));
    return {x1:x1+appliedX,y1:y1+appliedY,x2:x2+appliedX,y2:y2+appliedY};
  }
  const boxWidth=Math.min(width,Math.max(1,finite(pixels.width,'pixel width'))),boxHeight=Math.min(height,Math.max(1,finite(pixels.height,'pixel height')));
  return {left:Math.min(width-boxWidth,Math.max(0,finite(pixels.left,'left')+dx)),top:Math.min(height-boxHeight,Math.max(0,finite(pixels.top,'top')+dy)),width:boxWidth,height:boxHeight};
}

export function numberOrdinal(items, itemId) {
  let ordinal=0;
  for(const item of Array.isArray(items)?items:[]){
    if(item?.annotation_type_code==='number')ordinal+=1;
    if(String(item?.public_id)===String(itemId))return item?.annotation_type_code==='number'?ordinal:0;
  }
  return 0;
}
