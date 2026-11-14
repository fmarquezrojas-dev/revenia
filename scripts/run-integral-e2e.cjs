// Dedicated Docker project: no development database, volumes, or worker is touched.
const {spawnSync}=require('node:child_process')
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto')
const project='facturly-e2e-'+crypto.randomBytes(6).toString('hex')
const evidence=process.env.E2E_EVIDENCE_DIR||path.join(__dirname,'../docs/testing/evidence');fs.mkdirSync(evidence,{recursive:true})
const log=fs.openSync(path.join(evidence,'clean-e2e-run.txt'),'w')
function run(bin,args){console.log(bin,args.join(' '));const r=spawnSync(bin,args,{stdio:['ignore',log,log],windowsHide:true,env:{...process.env,E2E_PROJECT:project}});if(r.error)throw r.error;if(r.status!==0)throw new Error(`${bin} exited ${r.status}; see clean-e2e-run.txt`)}
const compose=['compose','-p',project,'-f','compose.e2e.yaml']
try {
 run('docker',['build','-t','facturly-app','-f','deploy/docker/php.dev.Dockerfile','.'])
 run('docker',[...compose,'build'])
 run('docker',[...compose,'up','-d','app','frontend'])
 run('docker',[...compose,'exec','-T','app','php','tests/EndToEnd/fixtures.php'])
 run('docker',[...compose,'up','-d','worker','scheduler'])
 run(process.execPath,['scripts/test-integral-ui.cjs'])
 run(process.execPath,['scripts/test-integral-recovery.cjs'])
 run('docker',[...compose,'exec','-T','app','php','tests/EndToEnd/pdf-samples.php'])
 run(process.execPath,['scripts/test-integral-pdf.cjs'])
} catch(e){console.error(e.message);process.exitCode=1}
finally{const r=spawnSync('docker',[...compose,'down'],{stdio:['ignore',log,log],windowsHide:true});if(r.status!==0)process.exitCode=1;fs.closeSync(log);console.log('Isolated storage volume retained; project:',project)}
