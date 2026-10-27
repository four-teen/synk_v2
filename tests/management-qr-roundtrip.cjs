const assert=require('node:assert/strict');
const qrcode=require('../assets/vendor/libs/management-qr/qrcode.js');
const jsQR=require('../assets/vendor/libs/management-qr/jsQR.js');
const payload='SYNK-ATTENDANCE:'+'a7'.repeat(32);
const code=qrcode(0,'M');code.addData(payload);code.make();
const size=(code.getModuleCount()+8)*6,pixels=new Uint8ClampedArray(size*size*4).fill(255);
for(let y=0;y<size;y++)for(let x=0;x<size;x++){
  const col=Math.floor(x/6)-4,row=Math.floor(y/6)-4;
  if(row>=0&&col>=0&&row<code.getModuleCount()&&col<code.getModuleCount()&&code.isDark(row,col)){const i=(y*size+x)*4;pixels[i]=pixels[i+1]=pixels[i+2]=0;}
}
assert.equal(jsQR(pixels,size,size).data,payload);
const rotated=new Uint8ClampedArray(pixels.length);
for(let y=0;y<size;y++)for(let x=0;x<size;x++)for(let c=0;c<4;c++)rotated[((size-1-x)*size+y)*4+c]=pixels[(y*size+x)*4+c];
assert.equal(jsQR(rotated,size,size).data,payload);
console.log('Management QR: encoded student payload decoded correctly at both orientations.');
