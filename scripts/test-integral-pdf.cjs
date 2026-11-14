const {execFileSync}=require('node:child_process'),assert=require('node:assert/strict'),fs=require('node:fs')
const project=process.env.E2E_PROJECT
if(!/^facturly-e2e-[a-f0-9]{12}$/.test(project||''))throw new Error('Dedicated E2E project required')
const compose=['compose','-p',project,'-f','compose.e2e.yaml']
const run=(...tail)=>execFileSync('docker',[...compose,...tail],{encoding:'utf8',windowsHide:true,timeout:60000})
const evidence=process.env.E2E_EVIDENCE_DIR||'docs/testing/evidence';const path=require('node:path')
const report=[]
for(const [name,total,currency] of [['factura-pen','302.76','PEN'],['boleta-anonima','163.88','PEN'],['factura-usd-50-lineas','7369.00','USD']]){
 const file='/app/storage/e2e-pdf-samples/'+name+'.pdf'
 const text=run('exec','-T','app','pdftotext','-layout',file,'-')
 assert.ok(text.includes('Revenia'),name+': platform brand');assert.ok(!text.includes('Facturly'),name+': old visible brand');
 assert.ok(text.includes(total),name+': total');assert.ok(text.includes(currency),name+': currency');assert.ok(text.includes('Sin validez tributaria'),name+': disclaimer')
 assert.ok(text.includes('Peña')&&text.includes('Núñez'),name+': Unicode')
 const info=run('exec','-T','app','pdfinfo',file);assert.ok(/Title:.*Revenia/.test(info),name+': PDF title');assert.ok(/Author:.*Revenia/.test(info),name+': PDF author');const pages=Number(info.match(/Pages:\s+(\d+)/)[1])
 if(name.includes('50')){assert.ok(pages>1);for(let line=1;line<=50;line++)assert.ok(new RegExp('Linea '+line+'\\b').test(text),'missing line '+line)}
 for(const page of new Set([1,pages]))run('exec','-T','app','pdftoppm','-f',String(page),'-l',String(page),'-singlefile','-scale-to','1200','-png',file,'/app/storage/e2e-pdf-samples/'+name+'-page-'+page)
 report.push({name,pages,total,currency,unicode:true,real_renderer:true,brand:'Revenia',metadata:true})
}
fs.mkdirSync(path.join(evidence,'pdf'),{recursive:true})
run('cp','app:/app/storage/e2e-pdf-samples/.',path.join(evidence,'pdf'))
fs.writeFileSync(path.join(evidence,'pdf-report.json'),JSON.stringify({result:'PASS',samples:report},null,2))
