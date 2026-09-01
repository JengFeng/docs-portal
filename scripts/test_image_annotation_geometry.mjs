import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import * as Fabric from '../assets/vendor/fabric/index.min.mjs';
const { Rect } = Fabric;

globalThis.window=globalThis;
const measureContext={measureText:(text)=>({width:String(text).length*10}),save(){},restore(){},scale(){},translate(){},rotate(){},transform(){},setTransform(){},clearRect(){}};
Fabric.setEnv({window:globalThis,document:{createElement:()=>({getContext:()=>measureContext,style:{},setAttribute(){},classList:{add(){},remove(){}},hasAttribute:()=>false})},isTouchSupported:false,WebGLProbe:{}});
const source=fs.readFileSync(new URL('../assets/image-annotation-geometry.js',import.meta.url),'utf8');
vm.runInThisContext(source,{filename:'image-annotation-geometry.js'});
const G=globalThis.TWImageGeometry;
assert.ok(G,'geometry helper must be exposed');
function arrowShaftWorldEndpoints(group){
  const shaft=group.getObjects()[0],points=shaft.calcLinePoints(),matrix=shaft.calcTransformMatrix();
  return [
    Fabric.util.transformPoint(new Fabric.Point(points.x1,points.y1),matrix),
    Fabric.util.transformPoint(new Fabric.Point(points.x2,points.y2),matrix)
  ];
}

for(const [start,end] of [
  [{x:.2,y:.3},{x:.7,y:.8}], [{x:.7,y:.8},{x:.2,y:.3}],
  [{x:.7,y:.3},{x:.2,y:.8}], [{x:.2,y:.8},{x:.7,y:.3}]
]){
  const normalized=G.normalizeDrag(start,end);
  assert.deepEqual(normalized,{x:.2,y:.3,width:.5,height:.5});
  for(const size of [{width:1200,height:800},{width:600,height:400}]){
    const box=G.rectObjectBox(normalized,size,3);
    const rect=new Rect({...box,originX:'left',originY:'top',stroke:'#d83b3b',strokeWidth:3,fill:'transparent'});
    const outer=rect.getBoundingRect();
    assert.ok(Math.abs(outer.left-normalized.x*size.width)<.001,'mouse-down edge must remain stable');
    assert.ok(Math.abs(outer.top-normalized.y*size.height)<.001,'mouse-down top must remain stable');
    assert.ok(Math.abs(outer.width-normalized.width*size.width)<.001,'outer width must match normalized drag');
    assert.ok(Math.abs(outer.height-normalized.height*size.height)<.001,'outer height must match normalized drag');
    const saved=G.annotationFromOuter(outer,size);
    for(const key of ['x','y','width','height']) assert.ok(Math.abs(saved[key]-normalized[key])<.000001,`save/reload drift in ${key}`);
  }
}
const edgeText=G.pointAnnotationBox({x:.92,y:.97},{width:.25,height:.08});
assert.deepEqual(edgeText,{x:.92,y:.97,width:.08,height:.03},'point text dimensions must fit remaining normalized area');
const edgeNumber=G.pointAnnotationBox({x:.99,y:.98},{width:.05,height:.07});
assert.deepEqual(edgeNumber,{x:.99,y:.98,width:.01,height:.02},'point number dimensions must fit remaining normalized area');

const integrationItems=[
  {annotationType:'text',x:.2,y:.25,width:.3,height:.1,note:'Zoom stable',geometry:{text:'Zoom stable'},style:{textColor:'#123456'}},
  {annotationType:'number',x:.65,y:.2,width:.08,height:.1,note:'marker',geometry:{number:7},style:{stroke:'#345678'}},
  {annotationType:'arrow',x:.2,y:.2,width:.5,height:.5,note:'reverse',geometry:{x1:.7,y1:.7,x2:.2,y2:.2},style:{stroke:'#654321',strokeWidth:3}}
];
const savedBySize=[];
for(const size of [{width:1200,height:800},{width:600,height:400}]){
  const saved=[];
  for(const [index,item] of integrationItems.entries()){
    const object=G.fabricObjectFor(Fabric,item,index,size,{selectable:true});
    object.set({left:object.left+.05*size.width,top:object.top+.04*size.height,scaleX:object.scaleX*1.2,scaleY:object.scaleY*.8});
    object.setCoords();
    const displayedArrowEndpoints=item.annotationType==='arrow'?arrowShaftWorldEndpoints(object):null;
    const persisted=G.annotationFromFabricObject(Fabric,object,item,size);
    assert.ok(persisted.x>=0&&persisted.y>=0&&persisted.x+persisted.width<=1.00000001&&persisted.y+persisted.height<=1.00000001,`${item.annotationType} must remain bounded`);
    const reloaded=G.fabricObjectFor(Fabric,{...item,...persisted,geometry:persisted.geometry},index,size,{selectable:true});
    const roundTrip=G.annotationFromFabricObject(Fabric,reloaded,{...item,...persisted,geometry:persisted.geometry},size);
    for(const key of ['x','y','width','height']) assert.ok(Math.abs(roundTrip[key]-persisted[key])<.000001,`${item.annotationType} save/reload drift in ${key}: ${persisted[key]} -> ${roundTrip[key]} (object width ${reloaded.width}, scale ${reloaded.scaleX}, box ${JSON.stringify(reloaded.getBoundingRect())})`);
    if(item.annotationType==='arrow'){
      assert.ok(persisted.geometry.x1>persisted.geometry.x2&&persisted.geometry.y1>persisted.geometry.y2,'reverse arrow direction must survive move/scale');
      assert.ok(roundTrip.geometry.x1>roundTrip.geometry.x2&&roundTrip.geometry.y1>roundTrip.geometry.y2,'reverse arrow direction must survive reload');
      const reloadedArrowEndpoints=arrowShaftWorldEndpoints(reloaded);
      for(let endpoint=0;endpoint<2;endpoint++){
        assert.ok(Math.abs(reloadedArrowEndpoints[endpoint].x-displayedArrowEndpoints[endpoint].x)<.001,`arrow shaft endpoint ${endpoint+1} x must not drift after save/reload`);
        assert.ok(Math.abs(reloadedArrowEndpoints[endpoint].y-displayedArrowEndpoints[endpoint].y)<.001,`arrow shaft endpoint ${endpoint+1} y must not drift after save/reload`);
      }
    }
    saved.push(persisted);
  }
  savedBySize.push(saved);
}
for(let index=0;index<integrationItems.length;index++){
  for(const key of ['x','y','width','height']) assert.ok(Math.abs(savedBySize[0][index][key]-savedBySize[1][index][key])<.000001,`${integrationItems[index].annotationType} normalized ${key} must be zoom-stable: ${savedBySize[0][index][key]} vs ${savedBySize[1][index][key]}`);
  if(integrationItems[index].annotationType==='arrow') for(const key of ['x1','y1','x2','y2']) assert.ok(Math.abs(savedBySize[0][index].geometry[key]-savedBySize[1][index].geometry[key])<.000001,`arrow normalized ${key} must be zoom-stable`);
}

const flipItem={annotationType:'arrow',x:.2,y:.2,width:.5,height:.5,note:'flip',geometry:{x1:.7,y1:.7,x2:.2,y2:.2},style:{stroke:'#654321',strokeWidth:3}};
for(const size of [{width:1200,height:800},{width:600,height:400}]){
  const visibleArrow=G.fabricObjectFor(Fabric,flipItem,0,size,{selectable:true});
  const [shaft,head]=visibleArrow.getObjects();
  assert.ok(Number(shaft.strokeWidth)>=3,`arrow shaft must remain visually distinct from strikethrough text at ${size.width}px`);
  assert.ok(Number(head.width)>=24&&Number(head.height)>=24,`arrow head must remain unmistakable at ${size.width}px`);
}
for(const [axis,expected] of [['flipX',{x:'ascending',y:'descending'}],['flipY',{x:'descending',y:'ascending'}]]){
  const size={width:1200,height:800};
  const object=G.fabricObjectFor(Fabric,flipItem,0,size,{selectable:true});
  object.set({[axis]:true}); object.setCoords();
  const displayedEndpoints=arrowShaftWorldEndpoints(object);
  const persisted=G.annotationFromFabricObject(Fabric,object,flipItem,size);
  assert.equal(persisted.geometry.x1<persisted.geometry.x2,expected.x==='ascending',`${axis} must persist the displayed horizontal endpoint order`);
  assert.equal(persisted.geometry.y1<persisted.geometry.y2,expected.y==='ascending',`${axis} must persist the displayed vertical endpoint order`);
  const reloaded=G.fabricObjectFor(Fabric,{...flipItem,...persisted,geometry:persisted.geometry},0,size,{selectable:true});
  const roundTrip=G.annotationFromFabricObject(Fabric,reloaded,{...flipItem,...persisted,geometry:persisted.geometry},size);
  for(const key of ['x1','y1','x2','y2']) assert.ok(Math.abs(roundTrip.geometry[key]-persisted.geometry[key])<.000001,`${axis} arrow endpoint ${key} must survive reload`);
  const reloadedEndpoints=arrowShaftWorldEndpoints(reloaded);
  for(let endpoint=0;endpoint<2;endpoint++){
    assert.ok(Math.abs(reloadedEndpoints[endpoint].x-displayedEndpoints[endpoint].x)<.001,`${axis} shaft endpoint ${endpoint+1} x must not drift after reload`);
    assert.ok(Math.abs(reloadedEndpoints[endpoint].y-displayedEndpoints[endpoint].y)<.001,`${axis} shaft endpoint ${endpoint+1} y must not drift after reload`);
  }
}

const diagonalDirections=[
  [{x:.2,y:.2},{x:.7,y:.7}], [{x:.7,y:.7},{x:.2,y:.2}],
  [{x:.7,y:.2},{x:.2,y:.7}], [{x:.2,y:.7},{x:.7,y:.2}]
];
for(const size of [{width:1200,height:800},{width:600,height:400}]){
  for(const [start,end] of diagonalDirections){
    const box=G.normalizeDrag(start,end),item={annotationType:'arrow',...box,note:'flip matrix',geometry:{x1:start.x,y1:start.y,x2:end.x,y2:end.y},style:{stroke:'#654321',strokeWidth:3}};
    for(const [label,flips] of [['flipX',{flipX:true}],['flipY',{flipY:true}],['flipXY',{flipX:true,flipY:true}]]){
      const object=G.fabricObjectFor(Fabric,item,0,size,{selectable:true});
      object.set(flips); object.setCoords();
      const displayed=arrowShaftWorldEndpoints(object),persisted=G.annotationFromFabricObject(Fabric,object,item,size);
      const reloaded=G.fabricObjectFor(Fabric,{...item,...persisted,geometry:persisted.geometry},0,size,{selectable:true}),after=arrowShaftWorldEndpoints(reloaded);
      for(let endpoint=0;endpoint<2;endpoint++) for(const coordinate of ['x','y']) assert.ok(Math.abs(after[endpoint][coordinate]-displayed[endpoint][coordinate])<.001,`${label} ${size.width}px diagonal shaft endpoint ${endpoint+1} ${coordinate} must not drift`);
    }
  }
}

console.log('[OK] image annotation geometry and Fabric integration remain normalized, directional, bounded, and zoom-stable.');
