const {execFileSync}=require('node:child_process'),assert=require('node:assert/strict'),fs=require('node:fs')
const project=process.env.E2E_PROJECT
if(!/^facturly-e2e-[a-f0-9]{12}$/.test(project||''))throw new Error('Dedicated E2E project required')
const args=['compose','-p',project,'-f','compose.e2e.yaml']
const run=(...tail)=>execFileSync('docker',[...args,...tail],{encoding:'utf8',windowsHide:true,timeout:60000})
const status=()=>JSON.parse(run('exec','-T','app','php','tests/EndToEnd/outage.php','inspect'))
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms))
async function main(){
 run('stop','worker','scheduler');run('stop','redis')
 try {
  const committed=JSON.parse(run('exec','-T','app','php','tests/EndToEnd/outage.php','prepare'));assert.equal(committed.status,'queued')
  run('exec','-T','app','php','artisan','issuance:tick');const failed=status();assert.equal(failed.publication_error,'queue_unavailable');assert.equal(failed.published,false)
  run('start','redis');await delay(12000)
  run('exec','-T','app','php','artisan','issuance:tick');const waiting=status();assert.equal(waiting.status,'queued');assert.equal(waiting.published,true)
  run('start','worker');let recovered
  for(let n=0;n<60;n++){recovered=status();if(recovered.status==='accepted')break;await delay(1000)}
  assert.equal(recovered.status,'accepted');assert.equal(recovered.attempts,1)
  run('exec','-T','app','php','tests/EndToEnd/outage.php','prepare-interrupt')
  run('exec','-T','app','php','artisan','issuance:tick')
  let interrupted
  for(let n=0;n<30;n++){interrupted=status();if(interrupted.paused)break;await delay(1000)}
  assert.equal(interrupted.status,'sending');assert.equal(interrupted.paused,true);assert.equal(interrupted.provider_calls,1)
  run('kill','-s','SIGKILL','worker');run('start','worker')
  let reconciled
  for(let n=0;n<180;n++){run('exec','-T','app','php','artisan','issuance:tick');reconciled=status();if(reconciled.status==='accepted')break;await delay(1000)}
  assert.equal(reconciled.status,'accepted');assert.equal(reconciled.attempts,2);assert.equal(reconciled.provider_calls,1)
  fs.writeFileSync(require('node:path').join(process.env.E2E_EVIDENCE_DIR||'docs/testing/evidence','recovery-report.json'),JSON.stringify({result:'PASS',redis_offline:failed,worker_stopped:waiting,recovered,worker_killed_after_provider_acceptance:interrupted,reconciled},null,2))
 }finally{run('start','redis','worker','scheduler')}
}
main().catch(e=>{console.error(e);process.exitCode=1})
