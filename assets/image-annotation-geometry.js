(() => {
  'use strict';
  const clamp=(value,min=0,max=1)=>Math.max(min,Math.min(max,Number(value)||0));
  const round=(value)=>Math.round(value*100000000)/100000000;
  window.TWImageGeometry=Object.freeze({
    normalizeDrag(start,end){
      const x1=clamp(start?.x),y1=clamp(start?.y),x2=clamp(end?.x),y2=clamp(end?.y);
      return {x:round(Math.min(x1,x2)),y:round(Math.min(y1,y2)),width:round(Math.abs(x2-x1)),height:round(Math.abs(y2-y1))};
    },
    pointAnnotationBox(point,dimensions){
      const x=clamp(point?.x,0,.9999),y=clamp(point?.y,0,.9999);
      return {x:round(x),y:round(y),width:round(Math.max(.0001,Math.min(1-x,clamp(dimensions?.width,.0001)))),height:round(Math.max(.0001,Math.min(1-y,clamp(dimensions?.height,.0001))))};
    },
    fabricObjectFor(fabric,item,index,canvasSize,options={}){
      const width=Math.max(.01,Number(canvasSize?.width)||0),height=Math.max(.01,Number(canvasSize?.height)||0);
      const box={left:clamp(item?.x)*width,top:clamp(item?.y)*height,width:clamp(item?.width)*width,height:clamp(item?.height)*height};
      const style=item?.style||{},shared=Object.assign({__annotationIndex:index,originX:'left',originY:'top'},options);
      if(item?.annotationType==='arrow'){
        const geometry=item.geometry||{},x1=clamp(geometry.x1??item.x)*width,y1=clamp(geometry.y1??item.y)*height,x2=clamp(geometry.x2??(item.x+item.width))*width,y2=clamp(geometry.y2??(item.y+item.height))*height;
        const shaftWidth=Math.max(3,(Number(style.strokeWidth)||3)*width/1200);
        const headSize=Math.max(24,Math.min(42,Math.min(width,height)*.04));
        const line=new fabric.Line([x1,y1,x2,y2],{stroke:style.stroke||'#d83b3b',strokeWidth:shaftWidth,selectable:false,evented:false});
        const angle=Math.atan2(y2-y1,x2-x1)*180/Math.PI+90;
        const head=new fabric.Triangle({left:x2,top:y2,width:headSize,height:headSize,fill:style.stroke||'#d83b3b',angle,originX:'center',originY:'center',selectable:false,evented:false});
        const group=new fabric.Group([line,head],Object.assign({subTargetCheck:false},shared));
        const initialBounds=group.getBoundingRect(),initialCenter=group.getCenterPoint();
        const logicalOriginX=clamp((clamp(item.x)*width-initialBounds.left)/Math.max(.01,initialBounds.width));
        const logicalOriginY=clamp((clamp(item.y)*height-initialBounds.top)/Math.max(.01,initialBounds.height));
        group.set({originX:logicalOriginX,originY:logicalOriginY});
        group.setPositionByOrigin(initialCenter,'center','center');
        group.setCoords();
        group.__arrowLine=line;
        group.__arrowTransform={left:group.left,top:group.top,scaleX:group.scaleX,scaleY:group.scaleY,flipX:Boolean(group.flipX),flipY:Boolean(group.flipY),anchorX:clamp(item.x),anchorY:clamp(item.y),x1:clamp(geometry.x1??item.x),y1:clamp(geometry.y1??item.y),x2:clamp(geometry.x2??(item.x+item.width)),y2:clamp(geometry.y2??(item.y+item.height))};
        return group;
      }
      if(item?.annotationType==='text'){
        const TextClass=fabric.Textbox||fabric.IText||fabric.Text;
        const object=new TextClass(String(item.geometry?.text||item.note||''),Object.assign({left:box.left,top:box.top,fontSize:Math.max(.01,box.height*.55),fill:style.textColor||'#d83b3b',strokeWidth:0},shared));
        object.set({scaleX:box.width/Math.max(.01,object.width),scaleY:box.height/Math.max(.01,object.height)});
        object.setCoords();
        return object;
      }
      if(item?.annotationType==='number'){
        const marker=String(item.geometry?.number??index+1),ellipse=new fabric.Ellipse({left:0,top:0,rx:50,ry:50,fill:style.stroke||'#d83b3b',originX:'center',originY:'center'}),text=new fabric.Text(marker,{left:0,top:0,fontSize:55,fill:'#fff',originX:'center',originY:'center'});
        if(text.width>80) text.scaleX=80/text.width;
        const group=new fabric.Group([ellipse,text],shared);
        group.set({left:box.left,top:box.top,scaleX:box.width/Math.max(.01,group.width),scaleY:box.height/Math.max(.01,group.height)});
        group.setCoords();
        return group;
      }
      throw new Error('unsupported Fabric annotation type');
    },
    annotationFromFabricObject(fabric,object,item,canvasSize){
      const width=Math.max(.01,Number(canvasSize?.width)||0),height=Math.max(.01,Number(canvasSize?.height)||0);
      if(item?.annotationType==='arrow'){
        const shaft=object.__arrowLine||object.getObjects?.()[0];
        if(!shaft||typeof shaft.calcLinePoints!=='function'||typeof shaft.calcTransformMatrix!=='function') throw new Error('arrow shaft transform missing');
        const points=shaft.calcLinePoints(),matrix=shaft.calcTransformMatrix();
        const first=fabric.util.transformPoint(new fabric.Point(points.x1,points.y1),matrix),second=fabric.util.transformPoint(new fabric.Point(points.x2,points.y2),matrix);
        const x1=round(clamp(first.x/width)),y1=round(clamp(first.y/height)),x2=round(clamp(second.x/width)),y2=round(clamp(second.y/height));
        const x=Math.min(x1,x2),y=Math.min(y1,y2),arrowWidth=Math.max(.0001,Math.abs(x2-x1)),arrowHeight=Math.max(.0001,Math.abs(y2-y1));
        return {x:round(x),y:round(y),width:round(Math.min(1-x,arrowWidth)),height:round(Math.min(1-y,arrowHeight)),geometry:{...(item.geometry||{}),x:round(x),y:round(y),width:round(Math.min(1-x,arrowWidth)),height:round(Math.min(1-y,arrowHeight)),x1,y1,x2,y2}};
      }
      const saved=this.annotationFromOuter(object.getBoundingRect(),canvasSize);
      if(item?.annotationType==='text') saved.geometry={...(item.geometry||{}),text:String(object.text||item.note||'').slice(0,500)};
      else saved.geometry={...(item.geometry||{})};
      return saved;
    },
    rectObjectBox(annotation,canvasSize,strokeWidth=0){
      const width=Math.max(.01,Number(canvasSize?.width)||0),height=Math.max(.01,Number(canvasSize?.height)||0),stroke=Math.max(0,Number(strokeWidth)||0);
      return {left:clamp(annotation?.x)*width,top:clamp(annotation?.y)*height,width:Math.max(.01,clamp(annotation?.width)*width-stroke),height:Math.max(.01,clamp(annotation?.height)*height-stroke)};
    },
    annotationFromOuter(bounds,canvasSize){
      const width=Math.max(.01,Number(canvasSize?.width)||0),height=Math.max(.01,Number(canvasSize?.height)||0);
      const x=clamp((Number(bounds?.left)||0)/width),y=clamp((Number(bounds?.top)||0)/height);
      return {x:round(x),y:round(y),width:round(Math.max(.0001,Math.min(1-x,(Number(bounds?.width)||0)/width))),height:round(Math.max(.0001,Math.min(1-y,(Number(bounds?.height)||0)/height)))};
    }
  });
})();
