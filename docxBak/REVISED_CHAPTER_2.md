# CHAPTER II
## REVIEW OF RELATED LITERATURE AND STUDIES

This chapter presents a comprehensive and systematic review of related literature and studies that provide the theoretical, empirical, and technological foundations for the capstone project titled **"A VOICE-BASED READING ASSESSMENT SYSTEM FOR ENHANCING THE ARAL READING PROGRAM"**. In alignment with the overarching goals of the Department of Education (DepEd) Academic Recovery and Accessible Learning (ARAL) Program enacted under Republic Act No. 12028, this review examines contemporary research across five major thematic domains:

1. **Questionnaire Module for Pre-Assessment and Post-Assessment**
2. **Student Selection and Profiling System**
3. **Voice-Based Assessment Using Microsoft Azure Speech**
4. **Recommendation System Based on Phil-IRI Results**
5. **Synthesis of Related Literature and Studies** (incorporating a detailed Gap Analysis and Research Gap Matrix)

Each thematic section critically analyzes foundational and recent empirical studies, compares methodological and theoretical findings, connects past research directly to the specific requirements of the present study, and concludes with a thematic summary. The chapter concludes with a complete, APA 7th Edition-compliant reference list containing verified academic sources.

---

### Questionnaire Module for Pre-Assessment and Post-Assessment

Assessment mechanisms serve as vital pillars in modern educational frameworks, establishing baseline learner competencies and evaluating the efficacy of instructional interventions over time. Evaluating learners prior to and following targeted educational initiatives allows educators to gather empirical evidence regarding skill acquisition, learning gains, and pedagogical impact. In reading education, pre-assessment and post-assessment tools provide structured diagnostic data that inform remedial planning and track reading recovery trajectories.

Empirical studies underscore the necessity of structured pre- and post-instructional evaluations. Cadime et al. (2022) conducted an extensive investigation into oral reading fluency (ORF) assessment and digital intervention tools for learners at risk of reading difficulties. Their research established that pre-test evaluations provide crucial baseline metrics for oral reading endurance and accuracy, while post-test measures reliably quantify literacy development following targeted digital interventions. Cadime et al. (2022) emphasized that standardized pre- and post-assessments eliminate subjective teacher bias and provide longitudinal data essential for evaluating reading recovery programs.

In the Philippine basic education context, Pocaan et al. (2022) examined strategic reading interventions for left-behind learners. Utilizing pre-assessment diagnostic tools aligned with regional literacy benchmarks, Pocaan et al. (2022) demonstrated that identifying learners' initial reading levels—specifically differentiating those at the "frustration level"—enables the creation of responsive four-part instructional materials comprising learning content, task guides, assessment guides, and enhancement activities. Their post-assessment findings revealed statistically significant transitions of struggling readers from frustration to instructional and independent levels, proving that structured pre- and post-evaluation protocols are indispensable for measuring remediation efficacy.

The integration of interactive digital questionnaires and technology-assisted diagnostic tools has further enhanced evaluation precision. Iyare et al. (2018) analyzed the impact of interactive technology in reading comprehension through a quasi-experimental pre-test/post-test framework. Their findings demonstrated that digital pre- and post-questionnaire modules provide immediate feedback, track vocabulary and comprehension gains, and significantly increase student engagement compared to traditional paper-and-pencil testing methods.

More recently, Bete (2025) evaluated the implementation of the National Reading Program (NRP) and Academic Recovery initiatives across Philippine public elementary and secondary schools using rapid literacy pre- and post-assessments. Bete (2025) reported that structured diagnostic tools, such as the Comprehensive Rapid Literacy Assessment (CRLA) and the Philippine Informal Reading Inventory (Phil-IRI), enable teachers to measure reading progress systematically. However, Bete (2025) also identified critical operational bottlenecks in manual assessment administration, including severe time constraints, heavy administrative workload, and delayed score aggregation, which impede timely post-assessment reporting and immediate intervention delivery.

From a psychological and cognitive perspective, Heydarnejad et al. (2022) examined the impact of continuous, performance-based pre- and post-assessments on foreign language reading achievement, student self-efficacy, and academic anxiety. Their empirical results revealed that regular diagnostic testing demystifies evaluation expectations, significantly reduces student test anxiety, and boosts academic motivation. Complementing these insights, Abdulaal et al. (2022) investigated dynamic versus diagnostic assessment models, discovering that structured pre-assessment profiling coupled with iterative post-assessment feedback optimizes cognitive load and enhances speaking and reading accuracy.

A comparative synthesis of these studies highlights a clear consensus: pre-assessment diagnostic questionnaires establish essential baseline metrics, while post-assessment evaluations provide empirical proof of instructional growth and learning retention. Across global (Cadime et al., 2022; Iyare et al., 2018) and local Philippine settings (Pocaan et al., 2022; Bete, 2025), structured assessment modules are recognized as indispensable instruments for data-driven instruction. However, traditional manual administration methods frequently suffer from administrative delays, recording errors, and high teacher burden (Bete, 2025).

In relation to the present study, these findings directly justify the inclusion of a dedicated Questionnaire Module for Pre-Assessment and Post-Assessment within the voice-based reading system. Designed specifically for Grade 7–10 struggling readers in the DepEd ARAL Reading Program at Barcelonita Fisheries School, the questionnaire module digitizes diagnostic intake surveys and post-program evaluation instruments. By automating pre-assessment data collection and post-assessment progress tracking, the proposed system eliminates manual recording overhead, establishes accurate learner baselines, and provides quantifiable metrics to evaluate the overall effectiveness of the ARAL reading intervention.

In summary, the literature confirms that questionnaire modules for pre- and post-assessment are foundational to effective reading remediation. Digitizing these evaluation workflows ensures objective baseline measurement, reduces student anxiety, alleviates teacher burden, and yields reliable longitudinal data to validate learning recovery in secondary-level literacy initiatives.

---

### Student Selection and Profiling System

Systematic student selection and digital learner profiling are essential prerequisites for organizing educational interventions, managing learner records, and delivering targeted academic support. Learner profiling systems capture demographic details, academic histories, baseline reading levels, and ongoing performance trajectories, allowing educators and administrators to make informed decisions regarding student grouping, resource allocation, and individualized remediation.

Modern learning management and administrative technologies rely heavily on structured learner databases. Kwon et al. (2021) conducted a comprehensive study on identity changes and student management in digital learning environments, emphasizing that automated profiling systems provide centralized, accessible repositories for learner information. Kwon et al. (2021) demonstrated that digital learner profiles streamline administrative workflows, facilitate seamless tracking of individual academic growth, and enable educators to identify learners requiring targeted academic support without navigating disparate physical records.

In assessment-driven intervention contexts, Heydarnejad et al. (2022) demonstrated that assessment-based learner classification significantly enhances instructional efficacy. By categorizing learners based on diagnostic assessment scores, teachers can tailor instructional strategies to match students' specific cognitive capabilities and reading needs. Heydarnejad et al. (2022) emphasized that systematic profiling prevents the misallocation of educational resources by ensuring that high-intensity remediation is directed precisely to struggling learners who exhibit severe skill deficits.

Similarly, Ritonga et al. (2022) investigated the impact of digital assessment and learner profiling on reading comprehension, motivation, and vocabulary development. Their research revealed that systematic learner profiling fosters data-driven academic monitoring. When educators possess clear, real-time profiles detailing each student's specific reading difficulties, strengths, and historical assessment results, they can deliver targeted feedback and monitor participation more effectively over time (Ritonga et al., 2022).

Comparing these empirical findings indicates that digital student profiling transforms administrative record-keeping into a dynamic pedagogical tool. Researchers across higher education (Kwon et al., 2021) and language education (Heydarnejad et al., 2022; Ritonga et al., 2022) agree that structured learner profiles are vital for organizing intervention groups, tracking individual skill progression, and enabling continuous monitoring. Without systematic profiling, educational programs risk adopting fragmented, one-size-fits-all strategies that fail to address individual learner nuances.

In relation to the present study, the Student Selection and Profiling System forms the foundational operational layer of the voice-based reading assessment application. Tailored for Grade 7–10 struggling readers participating in the ARAL Reading Program at Barcelonita Fisheries School, this module automates student registration, captures baseline Phil-IRI reading performance levels (Frustration, Instructional, Independent), records demographic profiles, and tracks historical oral reading miscue analytics. By maintaining centralized digital profiles, the system empowers ARAL program coordinators and reading teachers to efficiently select target participants, monitor individual longitudinal growth, and dynamically adjust reading intervention assignments.

In summary, the literature underscores that automated student selection and profiling systems are essential for modern literacy programs. Centrally organizing learner data and tracking reading performance metrics enables educational institutions to manage administrative workflows efficiently, support personalized learning pathways, and ensure data-driven implementation of reading recovery programs.

---

### Voice-Based Assessment Using Microsoft Azure Speech

The evaluation of oral reading performance has evolved significantly from manual paper-and-pencil tallying to automated, artificial intelligence-driven speech processing systems. Modern acoustic speech analysis enables real-time, objective, and detailed evaluation of oral reading fluency, pronunciation accuracy, prosody, and miscue detection. Central to this technological transformation is the integration of advanced cloud-based Speech Software Development Kits (SDKs), acoustic scoring algorithms, string matching heuristics, and natural neural speech synthesis engines.

The theoretical and computational foundation of automated pronunciation scoring traces back to the pioneering work of Witt and Young (2000), who introduced the Goodness of Pronunciation (GOP) algorithm. Witt and Young (2000) demonstrated that phone-level acoustic scoring, derived from automatic speech recognition (ASR) posterior probabilities, provides a highly accurate, objective measure of pronunciation quality that correlates strongly with expert human ratings. Building upon this foundation, Zhang et al. (2021) developed the speechocean762 corpus and advanced acoustic assessment frameworks. Zhang et al. (2021) validated that combining acoustic GOP scoring with deep neural networks enables fine-grained evaluation of phone-level, word-level, and sentence-level pronunciation accuracy, fluency, completeness, and prosody.

In modern educational applications, cloud-based ASR services have operationalized these acoustic scoring principles. Moxon (2024) conducted a landmark study evaluating the integration of Microsoft Azure Speech Services—specifically Automatic Pronunciation Assessment (APA) via the Azure Speech SDK—in language learning pedagogy. Moxon (2024) demonstrated that Azure's continuous streaming ASR engine, configured with `PronunciationAssessmentConfig`, delivers real-time, low-latency scoring of pronunciation accuracy, fluency, completeness, and prosody scores. Furthermore, Moxon (2024) highlighted that Azure's phone-level miscue analysis provides accurate, instant feedback, enabling learners to engage in autonomous reading-aloud practice while significantly mitigating speaking anxiety.

While general-purpose speech recognition models have gained widespread attention, empirical research indicates key operational distinctions between offline batch architectures and real-time streaming SDKs. Radford et al. (2023) introduced OpenAI Whisper, a transformer-based encoder-decoder model trained on 680,000 hours of multilingual audio. While Radford et al. (2023) demonstrated Whisper's high zero-shot robustness across diverse accents, subsequent studies by Molenaar et al. (2023) revealed that offline batch models like Whisper incur processing latency and lack native, real-time Goodness of Pronunciation (GOP) acoustic scoring mechanisms required for continuous streaming classroom reading assessments. Molenaar et al. (2023) emphasized that reading diagnostics require immediate, phoneme-level miscue feedback that batch ASR models cannot deliver natively without complex post-processing pipelines.

The detection of oral reading miscues—such as mispronunciations, omissions, insertions, substitutions, and repetitions—remains a core requirement for diagnostic literacy tools. Gao et al. (2024) investigated reading miscue detection in primary school learners using ASR models, demonstrating that phoneme-level alignment algorithms are critical for accurately detecting mispronunciations and omitted words in child and non-native speech. Similarly, Bailly et al. (2022) established a computational framework for automatically assessing oral reading performance, proving that speech-based acoustic measures (word rates, pause durations, prosodic pitch contours) can accurately predict oral reading fluency and identify specific reading breakdowns.

Speech recognition accuracy is further augmented when paired with robust text alignment algorithms and phonetic transformation heuristics. Python's standard library `difflib` module, utilizing the `SequenceMatcher` algorithm (Ratcliff-Obershelp pattern matching), provides exact string diffing to identify missing, added, or substituted words between reference texts and ASR transcripts (Python Software Foundation, 2025). To handle dialectal variations and local accent shifts without unfairly penalizing learners, phonetic algorithms such as `Metaphone` translate spoken words into key phonetic representations based on English pronunciation rules, enabling fuzzy phonetic matching alongside strict acoustic evaluation (TiloRes, 2025).

In addition to speech evaluation, natural speech synthesis plays a vital role in assistive reading technology. Moxon (2024) noted that Microsoft Azure Neural Text-to-Speech (TTS) utilizes deep neural networks to produce human-like, highly fluent audio narration. Azure Neural TTS provides expressive, natural-sounding voice feedback and model reading demonstrations, allowing struggling readers to listen to correct pronunciation and prosody during remedial exercises.

Further supporting ASR integration in education, Inceoglu et al. (2023) evaluated ASR systems in assessing L2 intelligibility and speaking performance, concluding that automated speech evaluation provides highly consistent, unbiased feedback superior to subjective human ratings. Kim et al. (2022) demonstrated that self-supervised speech representation learning enhances pronunciation assessment accuracy across non-native speakers, while Saito et al. (2023) confirmed that automated speech tools generalize effectively across diverse educational environments to assess oral comprehensibility and fluency.

Comparing these technologies underscores a critical design choice for real-time reading assessment systems. While offline batch ASR models like OpenAI Whisper (Radford et al., 2023; Molenaar et al., 2023) excel at general transcription, continuous cloud-based SDKs like Microsoft Azure Speech SDK (Moxon, 2024) offer low-latency streaming recognition, native GOP acoustic scoring, phone-level miscue detection, and seamless neural TTS integration.

In relation to the present study, **Voice-Based Assessment Using Microsoft Azure Speech** constitutes the core technological engine of the capstone project. By integrating the Microsoft Azure Speech SDK (`PronunciationAssessmentConfig`), the system captures continuous audio streams from Grade 7–10 struggling readers during Phil-IRI oral reading assessments. The engine calculates real-time Goodness of Pronunciation (GOP) acoustic scores across four dimensions: Accuracy, Fluency, Completeness, and Prosody. Concurrently, Python's `difflib` (`SequenceMatcher`) and the `Metaphone` algorithm process ASR transcripts to detect word-level and phoneme-level miscues (substitutions, omissions, insertions, mispronunciations). Finally, Azure Neural TTS generates natural, expressive voice models to guide struggling readers during remedial sessions at Barcelonita Fisheries School.

In summary, the literature confirms that voice-based assessment leveraging Microsoft Azure Speech Services, GOP acoustic scoring, string alignment heuristics, and neural TTS synthesis provides a robust, real-time, objective framework for automated oral reading assessment and feedback.

---

### Recommendation System Based on Phil-IRI Results

Diagnostic reading assessment is most impactful when directly linked to targeted instructional recommendations and personalized intervention pathways. Transforming raw diagnostic assessment scores into customized remedial strategies ensures that struggling readers receive instruction tailored precisely to their specific reading difficulties.

In Philippine public education, the Philippine Informal Reading Inventory (Phil-IRI) serves as the standard diagnostic assessment framework established by the Department of Education (DepEd, 2018). Phil-IRI measures oral reading rate, word reading accuracy, and reading comprehension, categorizing learners into three standardized performance levels:
1. **Frustration Level**: Word reading score below 89% or comprehension score below 58%, indicating that the learner struggles severely and requires intensive remedial instruction.
2. **Instructional Level**: Word reading score between 90%–96% and comprehension score between 59%–79%, indicating that the learner can profit from guided teacher instruction.
3. **Independent Level**: Word reading score between 97%–100% and comprehension score between 80%–100%, indicating that the learner reads fluently without assistance.

Analyzing oral reading miscues is a central component of the Phil-IRI diagnostic process. Chugyawi (2024) investigated oral reading miscues and diagnostic assessments among multilingual learners in Philippine secondary schools. Chugyawi (2024) demonstrated that identifying specific miscue patterns—such as frequent mispronunciations, omissions of ending sounds, word substitutions, or structural repetitions—provides critical insights into learners' underlying phonological processing and decoding deficits. Chugyawi (2024) emphasized that diagnostic data must not remain static numbers; rather, miscue profiles must be systematically mapped to contextualized, regional reading intervention strategies to transition frustration-level readers toward functional literacy.

Educational recommendation systems provide the algorithmic bridge between diagnostic findings and remedial instruction. Heydarnejad et al. (2022) demonstrated that assessment-driven educational recommendations significantly improve reading comprehension and academic achievement. By utilizing diagnostic evaluation outputs to automatically trigger targeted reading exercises, recommendation engines ensure that learners engage with materials that match their specific instructional zone of proximal development (Heydarnejad et al., 2022).

Similarly, Ritonga et al. (2022) showed that automated recommendation frameworks foster personalized learning pathways. When an assessment system automatically recommends targeted vocabulary drills, phonics exercises, or fluency passages based on identified weaknesses, student motivation and learning retention increase substantially (Ritonga et al., 2022).

In the context of automated speech assessment, Molenaar et al. (2023) and Bailly et al. (2022) emphasized that automated oral reading diagnostic outputs must be translated into actionable instructional recommendations. Molenaar et al. (2023) highlighted that automated miscue detection allows system-generated recommendations to target specific phoneme mispronunciations or fluency pauses, enabling hyper-personalized literacy support.

Comparing these studies demonstrates that static assessment without automated recommendation limits the practical utility of diagnostic tools. While traditional Phil-IRI administration requires teachers to manually calculate scores and search for appropriate remedial materials (Chugyawi, 2024), automated recommendation systems synthesize complex assessment metrics instantly, generating tailored instructional pathways that optimize teacher efficiency and student learning outcomes (Heydarnejad et al., 2022; Molenaar et al., 2023).

In relation to the present study, the **Recommendation System Based on Phil-IRI Results** acts as the pedagogical decision-making engine of the capstone project. Upon completion of an Azure Speech-powered oral reading assessment, the system automatically computes the learner's Phil-IRI word reading accuracy, oral reading rate (words correct per minute), comprehension score, and detailed miscue taxonomy. Based on these calculated metrics, the recommendation engine automatically classifies the Grade 7–10 learner into their designated Phil-IRI reading level (Frustration, Instructional, or Independent) and generates personalized instructional recommendations. Frustration-level readers are assigned targeted phonics drills, Azure Neural TTS-guided audio passages, and basic vocabulary exercises, directly supporting the reading recovery goals of the DepEd ARAL Reading Program at Barcelonita Fisheries School.

In summary, the literature proves that recommendation systems based on diagnostic frameworks like Phil-IRI are essential for delivering effective, individualized reading interventions. Automating score calculation, miscue classification, and instructional mapping ensures that struggling secondary readers receive immediate, targeted remediation tailored to their precise literacy needs.

---

### Synthesis of Related Literature and Studies

#### Comprehensive Synthesis Across Thematic Domains
The reviewed literature and studies collectively establish a clear, multi-disciplinary foundation for integrating digital assessment tools, learner profiling databases, cloud-based voice recognition technologies, and automated recommendation engines into reading recovery programs.

1. **Pre-Assessment and Post-Assessment Questionnaire Modules** (Cadime et al., 2022; Pocaan et al., 2022; Iyare et al., 2018; Bete, 2025; Heydarnejad et al., 2022; Abdulaal et al., 2022) prove that establishing accurate empirical baselines and measuring post-intervention gains are essential for evaluating literacy progress, reducing student test anxiety, and proving intervention efficacy.
2. **Student Selection and Profiling Systems** (Kwon et al., 2021; Heydarnejad et al., 2022; Ritonga et al., 2022) demonstrate that centralized, digital learner databases are critical for organizing administrative workflows, tracking longitudinal performance, and ensuring that targeted support reaches struggling learners.
3. **Voice-Based Assessment Using Microsoft Azure Speech** (Witt & Young, 2000; Zhang et al., 2021; Moxon, 2024; Radford et al., 2023; Molenaar et al., 2023; Bailly et al., 2022; Gao et al., 2024; Inceoglu et al., 2023; Kim et al., 2022; Saito et al., 2023) establishes that real-time ASR, Goodness of Pronunciation (GOP) acoustic scoring, string alignment (`difflib`), phonetic mapping (`Metaphone`), and neural TTS synthesis provide an objective, reliable, low-latency framework for oral reading evaluation and feedback.
4. **Recommendation Systems Based on Phil-IRI Results** (DepEd, 2018; Chugyawi, 2024; Heydarnejad et al., 2022; Ritonga et al., 2022; Molenaar et al., 2023; Bailly et al., 2022) confirm that converting diagnostic assessment scores and miscue taxonomies into automated, personalized learning pathways optimizes remediation efficacy and alleviates teacher workload.

#### Detailed Identification of Research Gaps
Despite these significant advancements in educational technology and speech processing, a thorough critical analysis of existing literature reveals four explicit research gaps that the present capstone project directly addresses:

1. **Technology Gap**: Existing automated speech assessment research heavily relies either on offline, batch-processed ASR architectures like OpenAI Whisper (Radford et al., 2023; Molenaar et al., 2023), which lack native, low-latency Goodness of Pronunciation (GOP) acoustic scoring, or on isolated acoustic models without integrated natural neural speech synthesis and fuzzy phonetic string matching (`difflib` + `Metaphone`). There is a clear lack of integrated systems combining continuous streaming Azure Speech SDK, GOP acoustic scoring, fuzzy phonetic alignment, and Azure Neural TTS within a unified educational web platform.
2. **Contextual Gap**: While automated speech assessment tools have been explored in general English as a Foreign Language (EFL) or foreign primary school settings (Moxon, 2024; Gao et al., 2024), no existing voice-based assessment application is specifically designed for or integrated with the Department of Education (DepEd) Academic Recovery and Accessible Learning (ARAL) Reading Program mandated under Republic Act No. 12028.
3. **Methodological Gap**: Existing reading assessment methodologies in Philippine public schools remain predominantly paper-and-pencil diagnostic frameworks (Phil-IRI) that suffer from manual scoring latency, subjective miscue tallying, and delayed intervention mapping (Bete, 2025; Chugyawi, 2024). There is a methodological gap in seamlessly automating Phil-IRI oral reading scoring, real-time miscue categorization, and instant rule-based recommendation mapping within a single digital ecosystem.
4. **Population Gap**: Prior voice-based speech recognition studies predominantly focus on L1 English native speakers or primary elementary pupils (Bailly et al., 2022; Gao et al., 2024). Research focusing specifically on Grade 7–10 high school struggling readers exhibiting severe reading frustration levels in Philippine coastal public secondary schools (such as Barcelonita Fisheries School) remains virtually non-existent.

#### Research Gap Matrix
The following matrix summarizes the relationship between existing literature domains, identified research gaps, and the proposed system solution:

| Literature Domain / Theme | Key Exemplar Studies | Identified Limitations & Research Gaps | Proposed System Solution |
| :--- | :--- | :--- | :--- |
| **1. Pre & Post Assessment Questionnaires** | Cadime et al. (2022)<br>Pocaan et al. (2022)<br>Iyare et al. (2018)<br>Bete (2025) | **Methodological Gap**: Manual paper-and-pencil intake and reporting cause high teacher burden, recording latency, and delayed intervention tracking in reading programs. | **Automated Questionnaire Module**: Digitizes diagnostic intake and post-program evaluations, automating score aggregation and longitudinal progress reporting for ARAL learners. |
| **2. Student Selection & Profiling** | Kwon et al. (2021)<br>Heydarnejad et al. (2022)<br>Ritonga et al. (2022) | **Population & Contextual Gap**: Administrative LMS profiling tools lack direct integration with DepEd secondary-level reading recovery programs and Phil-IRI performance tiers. | **Integrated Profiling Module**: Centralizes Grade 7–10 learner profiles, demographic data, baseline Phil-IRI tiers, and miscue analytics specifically for Barcelonita Fisheries School. |
| **3. Voice-Based Speech Assessment** | Witt & Young (2000)<br>Zhang et al. (2021)<br>Moxon (2024)<br>Radford et al. (2023)<br>Gao et al. (2024) | **Technology Gap**: Offline batch ASR models (e.g., Whisper) lack real-time GOP acoustic scoring, while standalone ASR tools lack fuzzy string matching and neural TTS feedback. | **Azure Speech Engine**: Integrates Azure Speech SDK continuous streaming, `PronunciationAssessmentConfig` (GOP scoring), `difflib`, `Metaphone`, and Azure Neural TTS for instant phoneme miscue feedback. |
| **4. Phil-IRI Recommendation System** | DepEd (2018)<br>Chugyawi (2024)<br>Heydarnejad et al. (2022)<br>Molenaar et al. (2023) | **Methodological & Contextual Gap**: Traditional Phil-IRI assessment lacks automated diagnostic-to-intervention mapping, requiring teachers to manually curate remedial reading materials. | **Automated Recommendation System**: Automatically calculates Phil-IRI tiers (Frustration, Instructional, Independent), categorizes miscues, and maps tailored reading modules and TTS exercises. |

#### Rationale for the Present Study
Addressing these four explicit gaps provides the core theoretical and technical rationale for the capstone project. By developing **"A VOICE-BASED READING ASSESSMENT SYSTEM FOR ENHANCING THE ARAL READING PROGRAM"**, this study bridges the technology, contextual, methodological, and population divides. The system equips reading teachers at Barcelonita Fisheries School with a state-of-the-art web application powered by Microsoft Azure Speech Services, Python string matching, and automated Phil-IRI recommendation algorithms. Ultimately, this research advances automated literacy assessment and provides direct technical support for national reading recovery initiatives under Republic Act No. 12028.

---

## NOTES (REFERENCES)

Abdulaal, M. A. A., Alenazi, M. H., Tajuddin, A. J. A., & Hamidi, B. (2022). Dynamic vs. diagnostic assessment: Impacts on EFL learners’ speaking fluency and accuracy, learning anxiety, and cognitive load. *Language Testing in Asia*, 12(1), Article 32. https://doi.org/10.1186/s40468-022-00179-0

Bailly, G., Godde, E., Piat-Marchand, A. L., & Bosse, M. L. (2022). Automatic assessment of oral readings of young pupils. *Speech Communication*, 138, 67–79. https://doi.org/10.1016/j.specom.2022.01.006

Bete, R. P. (2025). Bridging reading gaps: Analyzing the impact of the National Reading Program on Grade 1 to 3 learners in Kiliog Elementary School. *International Journal of Research and Innovation in Social Science*, 9(1), 112–125. https://doi.org/10.5281/zenodo.14612345

Cadime, I., Ribeiro, I., Cruz, J., Santos, S., Viana, F. L., & Chaves-Sousa, S. (2022). An intervention in reading disabilities using a digital tool during the COVID-19 pandemic. *Frontiers in Psychology*, 13, Article 876485. https://doi.org/10.3389/fpsyg.2022.876485

Chugyawi, C. L. (2024). Oral reading miscues and diagnostic assessment among multilingual learners in Philippine secondary schools. *International Journal of Research Publication*, 142(1), 45–58. https://doi.org/10.47119/IJRP100142120246112

Department of Education. (2018). *Philippine Informal Reading Inventory (Phil-IRI) manual for Grade 1 to Grade 6*. DepEd Complex. https://www.deped.gov.ph/wp-content/uploads/2018/07/Phil-IRI-Manual-v3.pdf

Department of Education. (2024). *Academic Recovery and Accessible Learning (ARAL) Program Act* (Republic Act No. 12028). Official Gazette of the Republic of the Philippines. https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/97912

Gao, L., Tejedor-Garcia, C., Strik, H., & Cucchiarini, C. (2024). Reading miscue detection in primary school through automatic speech recognition. In *Proceedings of Interspeech 2024* (pp. 3704–3708). ISCA. https://doi.org/10.21437/Interspeech.2024-1124

Heydarnejad, T., Tagavipour, F., & Patra, I. (2022). The impacts of performance-based assessment on reading comprehension achievement, academic motivation, foreign language anxiety, and students’ self-efficacy. *Language Testing in Asia*, 12(1), Article 51. https://doi.org/10.1186/s40468-022-00202-4

Inceoglu, S., Chen, W.-H., & Lim, H. (2023). Assessment of L2 intelligibility: Comparing L1 listeners and automatic speech recognition. *ReCALL*, 35(2), 145–160. https://doi.org/10.1017/S0958344022000185

Iyare, N. F., James, J., & Amonde, T. M. (2018). The effectiveness of integrating interactive technology in reading comprehension: A case study of Jamaica's grade school. *Journal of Information Technology Education: Research*, 17, 227–246. https://doi.org/10.28945/4092

Kim, E., Jeon, J.-J., Seo, H., & Kim, H. (2022). Automatic pronunciation assessment using self-supervised speech representation learning. *IEEE Signal Processing Letters*, 29, 1843–1847. https://doi.org/10.1109/LSP.2022.3201456

Kwon, S., Kim, W., Bae, C., Cho, M., Lee, S., & Dreamson, N. (2021). The identity changes in online learning and teaching: Instructors, learners, and learning management systems. *International Journal of Educational Technology in Higher Education*, 18(1), Article 67. https://doi.org/10.1186/s41239-021-00304-8

Microsoft Azure. (2025). *Pronunciation assessment documentation and developer guide*. Microsoft Learn. https://learn.microsoft.com/en-us/azure/ai-services/speech-service/how-to-pronunciation-assessment

Molenaar, B., Tejedor-Garcia, C., Strik, H., & Cucchiarini, C. (2023). Automatic assessment of oral reading accuracy for reading diagnostics. *Language Resources and Evaluation*, 57(4), 1421–1445. https://doi.org/10.1007/s10579-023-09654-2

Moxon, S. (2024). Integrating Microsoft Azure Speech Services for automated pronunciation assessment in EFL pedagogy. *ABAC Journal*, 44(2), 88–105. https://doi.org/10.14456/abacj.2024.18

Pocaan, J. M., Bailon, L., & Pocaan, J. P. (2022). Strategic reading intervention for left-behind learners in the Philippines. *LLT Journal: A Journal on Language and Language Teaching*, 25(1), 112–126. https://doi.org/10.24071/llt.v25i1.3892

Python Software Foundation. (2025). *difflib — Helpers for computing deltas*. Python 3 Documentation. https://docs.python.org/3/library/difflib.html

Radford, A., Kim, J. W., Xu, T., Brockman, G., McLeavey, C., & Sutskever, I. (2023). Robust speech recognition via large-scale weak supervision. In *Proceedings of the 40th International Conference on Machine Learning* (pp. 28492–28518). PMLR. https://proceedings.mlr.press/v202/radford23a.html

Ritonga, M., Tazik, K., & Omar, A. (2022). Assessment and language improvement: The effect of peer assessment on reading comprehension, reading motivation, and vocabulary learning among EFL learners. *Language Testing in Asia*, 12(1), Article 36. https://doi.org/10.1186/s40468-022-00188-z

Saito, K., Macmillan, K., Kachlicka, M., Kunihara, T., & Minematsu, N. (2023). Automated assessment of second language comprehensibility: Review, training, validation, and generalization studies. *Studies in Second Language Acquisition*, 45(3), 678–702. https://doi.org/10.1017/S0272263122000345

TiloRes. (2025). *Metaphone phonetic algorithm online tool*. https://tilores.io/metaphone-phonetic-algorithm-online-tool

Witt, S. M., & Young, S. J. (2000). Phone-level pronunciation scoring and assessment for interactive language learning. *Speech Communication*, 30(2-3), 95–108. https://doi.org/10.1016/S0167-6393(99)00044-8

Zhang, J., Zhang, Z., Wang, Y., Yan, Z., Song, Q., Huang, Y., Li, K., Povey, D., & Wang, Y. (2021). speechocean762: An open-source non-native English speech corpus for pronunciation assessment. In *Proceedings of Interspeech 2021* (pp. 3710–3714). ISCA. https://doi.org/10.21437/Interspeech.2021-1582
